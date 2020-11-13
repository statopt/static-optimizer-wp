<?php

class Static_Optimizer_Asset_Optimizer {
	private $cfg = [];
	private $host = '';
	private $doc_root = '';

	// default servers
	private $servers = [
		'http://us1.statopt.net:5080',
		'https://us1.statopt.net:5443',
	];

	public function __construct($cfg = []) {
		$host = '';

		if (!empty($cfg['host'])) {
			$host = $cfg['host'];
		} elseif (!empty($_SERVER['HTTP_HOST'])) {
			$host = $_SERVER['HTTP_HOST'];
		} elseif (!empty($_SERVER['SERVER_NAME'])) {
			$host = $_SERVER['SERVER_NAME'];
		}

		$host = preg_replace( '#^www\.#si', '', $host );
		$host = strtolower( $host );
		$host = strip_tags($host);
		$host = trim($host);

		if (defined('ABSPATH')) {
			$this->doc_root = ABSPATH;
		} elseif (!empty($_SERVER['DOCUMENT_ROOT']))  {
			$this->doc_root = $_SERVER['DOCUMENT_ROOT'];
		}

		$this->cfg = $cfg;
		$this->host = $host;
	}

	/**
	 * Gets one optimization server
	 * @param array $ctx
	 * @return string
	 */
	public function getOptimizerServerUri($ctx = []) {
		$servers = $this->getServers();

		// Single server -> return it
		if (empty($servers)) {
			return '';
		}

		$is_ssl = !empty($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'off') != 0;

		// We'll leave ssl requests to go to ssl optimizer urls and non-ssl to non-ssl ones
		if ($is_ssl) {
			$filtered = preg_grep('#^https://#si', $servers);
		} else {
			$filtered = preg_grep('#^http://#si', $servers);
		}

		if (empty($filtered)) {
			return '';
		}

		if (!empty($filtered)) {
			$servers = $filtered;
		}

		// Pick a random static optimization server. If it's one element there won't be any randomness.
		$url = $servers[ array_rand($servers) ];

		return $url;
	}

	/**
	 * php docs: Some web servers (e.g. Apache) change the working directory of a script when calling the callback function.
	 * You can change it back by e.g. chdir(dirname($_SERVER['SCRIPT_FILENAME'])) in the callback function.
	 * https://www.php.net/ob_start
	 */
	public function maybeCorrectScriptDir() {
		if (!empty($_SERVER['SCRIPT_FILENAME'])
		    && !empty($_SERVER['SERVER_SOFTWARE'])
		    && (stripos($_SERVER['SERVER_SOFTWARE'], 'apache') !== false)
	    ) {
			chdir(dirname($_SERVER['SCRIPT_FILENAME']));
		}
	}

	/**
	 * Parses the output and checks if we need to replace any links. Exit on first occasion.
	 * @param string $buff
	 * @return string
	 */
	public function run( $buff ) {
		static $appended_js = 0;

		// process output only if GET method.
		if (empty($_SERVER['REQUEST_METHOD']) || strcasecmp($_SERVER['REQUEST_METHOD'], 'get') != 0) {
			return $buff;
		}

		$first_char = substr( $buff, 0, 1 );

		// if this a json response? if so don't touch it
		if ($first_char == '{' || $first_char == '[') {
			return $buff;
		}

		$host = $this->getHost();

		if (empty($host)) {
			return $buff;
		}

		$small_buff = substr( $buff, 0, 32 );

		// if it starts like HTML there's huge chance that it's not a binary file
		if (substr($small_buff, 0, 1) != '<' && substr($small_buff, 1, 1) != '<' && substr($small_buff, 2, 1) != '<') {
			// This is how I got to these prefixes
			//    $f = 't.pdf';
			//    $buff = file_get_contents($f);
			//    $buff = substr($buff, 0, 10);
			//    echo urlencode($buff) . "\n";
			$known_starting_prefixes = [
				'PK%03', // Zip file
				'%89PNG', // png
				'GIF89a', // gif
				'%FF%D8%FF%E0%00%10JFIF', // jpeg
				'%25PDF', // pdf
				'%1F%8B%08%08%8Aj%E7%5E%04%00', // gzip
				'7z%BC%AF', // 7z
				'wOFF%00%', // woff font
				'wOF2%00', // woff2 font
			];

			// check for those known texts and don't process
			foreach ($known_starting_prefixes as $pref) {
				$decoded_pref = urldecode($pref);

				// known binary file -> not interested.
				if (substr($small_buff, 0, strlen($decoded_pref)) == $decoded_pref) {
					return $buff;
				}
			}
		}

		$small_buff = substr( $buff, 0, 1024 );

		// If it's not an HTML code don't modify anything.
		if ( stripos( $small_buff, '<head' ) === false ) {
			return $buff;
		}

		// there are no links for this host?
		if ( stripos( $buff, $host ) === false ) {
			return $buff;
		}

		$supported_ext_arr = [];
		$file_types = $this->cfg['file_types'];
		$file_types = empty($file_types) ? [] : $file_types;

		if (empty($file_types)) {
			return $buff;
		}

		// We'll pick only the keys of enabled file types
		$enabled_file_types = array_filter($file_types);
		$enabled_file_types = array_keys($enabled_file_types);

		$this->maybeCorrectScriptDir();

		$host_q = preg_quote( $host, '#' );
		$script_tag_found = false;

		// if the scripts do not have src we'll encode their inner contents so the replace method don't process them
		if (in_array('js', $enabled_file_types) && (stripos($buff, '<script') !== false)) {
			$script_tag_found = true;
			$all_assets_regex = '#(<script[^>]*>)(.*?)(</script>)#si';

			$buff = preg_replace_callback(
				$all_assets_regex,
				[ $this, 'protectScriptWithData' ],
				$buff
			);
		}

		if (in_array('images', $enabled_file_types)) {
			$supported_ext_arr[] = 'png';
			$supported_ext_arr[] = 'jpe?g';
			$supported_ext_arr[] = 'gif';
		}

		if (in_array('js', $enabled_file_types)) {
			$supported_ext_arr[] = 'js';
		}

		if (in_array('css', $enabled_file_types)) {
			$supported_ext_arr[] = 'css';
		}

		if (in_array('fonts', $enabled_file_types)) {
			$supported_ext_arr[] = 'eot';
			$supported_ext_arr[] = 'ttf';
			$supported_ext_arr[] = 'woff\d*';
		}

		$supported_ext = join('|', $supported_ext_arr);
		$host_prefix_regex = $this->getHostPrefixRegex();

		// @todo parse code and search for jquery + its version then link to google cdn ?
		// 1) parses static files that are probably in img src="....."
		// 2) parses inline bg images. background: url("https://us1.wpdemo.org/sample-store/bizberg/assets/images/breadcrum.jpg?v=aaa");
		// 3) ... src: url(https://us1.wpdemo.org/wpd_1596046364_8359/s-qvcc8mhby2kal.us1.wpdemo.org/wp-content/fonts/playfair-display/nuFvD-vYSZviVYUb_rj3ij__anPXJzDwcbmjWBN2PKdFvXDXbtXK-F2qC0s.woff) format('woff');
		// expects the version (if any) to be first param
		$buff = preg_replace_callback(
			'#(\s*(?:=|url\s*\()[\'"\s]*(?:https?://'
			. $host_prefix_regex
			. $host_q
			. '[\w\-/.]*)[\w\s:\-/.%]+?)\.(' . $supported_ext . ')'
			. '(?:[?&](?:hash|sha\d+|md5|ts?|version|ver|v|m|_)=([\w\-.]+))?([\'")]*)#si',
			[ $this, 'appendQSAssetVerReplaceCallback' ],
			$buff
		);

		if (in_array('images', $enabled_file_types)) {
			// We'll check if there are images with srcset attrib in a small buff
			$first_pos = stripos( $buff, '<img' );

			// <img src="http://example.com/image.png" srcset="http://example.com/wp-content/uploads/2020/07/Screenshot-from-2020-07-10-17-17-05-1024x576.png 1024w, http://example.com/wp-content/uploads/2020/07/Screenshot-from-2020-07-10-17-17-05-300x169.png 300w" />
			if ( $first_pos !== false ) {
				$all_images_regex = '#<img[^>]*>#si';

				$buff = preg_replace_callback(
					$all_images_regex,
					[ $this, 'correctImageSrcsetReplaceCallback' ],
					$buff
				);
			}
		}

		$css_load_found = false;

		if (in_array('css', $enabled_file_types) && (stripos($buff, '<link') !== false)) {
			$css_load_found = true;
		}

		// do we have css/script stuff?
		if ($script_tag_found || $css_load_found) {
			$js_css_search = [];

			if ($script_tag_found) {
				$js_css_search[] = 'script';
			}

			if ($css_load_found) {
				$js_css_search[] = 'link';
			}

			$regex_part = join('|', $js_css_search);
			$all_assets_regex = '#<(' . $regex_part . ')[^>]*>#si';

			$buff = preg_replace_callback(
				$all_assets_regex,
				[ $this, 'appendOnerrorReplaceCallback' ],
				$buff
			);
		}

		if ($appended_js <= 0) {
			$buff = str_ireplace('<head>', '<head>' . $this->generatePublicSideFallbackCode(), $buff); // first thing after <head>
			$appended_js++;
		}

		// did we escape some JS?
		if (strpos($buff, $this->customEscapeStart) !== false) {
			$st = preg_quote($this->customEscapeStart, '#');
			$end = preg_quote($this->customEscapeEnd, '#');

			$all_assets_regex = '#'. $st . '(.*?)' . $end . '#si';

			$buff = preg_replace_callback(
				$all_assets_regex,
				[ $this, 'unprotectScriptWithData' ],
				$buff
			);
		}

		// @todo parse WP load scripts js too
		// @todo parse local files starting with / or relative???
		// change jquery to google cdn ? or leave my code to run in WP to do it.
		// //<script onerror="javascript:static_optimizer_handle_broken_script(this);"
		// src='http://demo.qsandbox0.staging.com/qs3_1596199452_0089/s-qcsgy24aatlal.qsandbox0.staging.com/wp-admin/load-scripts.php?c=0&amp;load%5Bchunk_0%5D=jquery-core,jquery-migrate,utils&amp;ver=5.4.2'></script>

		return $buff;
	}

	private $customEscapeStart = "<!--static_optimizer_prot:";
	private $customEscapeEnd = ":/static_optimizer_prot -->";

	/**
	 * This receives <script>...</script> sections.
	 * if the script has src we won't encode its contents.
	 * @param $matches
	 * @return string
	 */
	function protectScriptWithData($matches) {
		static $cnt = 0;

		if ( stripos( $matches[1], 'src=' ) !== false ) { // this must be a script block that defines some info so leave it alne
			return $matches[0];
		}

		$str = $this->customEscapeStart . base64_encode(serialize($matches[0])) . $this->customEscapeEnd;
		$cnt++;

		return $str;
	}

	// This should revert the encoded text to <script>...</script> sections
	function unprotectScriptWithData($matches) {
		$str = $matches[1];
		$str = base64_decode($str);
		$str = unserialize($str);
		return $str;
	}

	// Appends onerror js handled (inline) for script & stylesheets
	function appendOnerrorReplaceCallback($matches) {
		$html_buff = $matches[0];

		if ( stripos( $html_buff, 'onerror=' ) !== false ) { // the item already has on error attrib
			return $html_buff;
		}

		$what = $matches[1];

		if (strcasecmp($what, 'script') == 0) {
			if ( stripos( $html_buff, 'src=' ) === false ) { // this must be a script block that defines some info so leave it alne
				return $html_buff;
			}

			$append_txt = ' onerror="javascript:static_optimizer_handle_broken_script(this);" ';
			$html_buff = str_ireplace('<script ', '<script ' . $append_txt, $html_buff);
		} elseif (strcasecmp($what, 'link') == 0 && ( stripos( $html_buff, 'stylesheet' ) !== false )) {
			$append_txt = ' onerror="javascript:static_optimizer_handle_broken_link(this);" onload="javascript:static_optimizer_handle_broken_link(this);" ';
			$html_buff = str_ireplace('<link ', '<link ' . $append_txt, $html_buff);
		}

		return $html_buff;
	}

	function generatePublicSideFallbackCode() {
		$json_str = json_encode([
			'server_name' => $this->getHost(),
		] );

		$buff = <<<BUFF_EOF
    <script>
    // StaticOptimizer fallback code if optimization servers are down.
    var static_optimizer_site_cfg = $json_str;

    function static_optimizer_handle_broken_image(img_obj) {
        var src = img_obj.currentSrc || img_obj.src || '';
        console.log("statopt: found src: " + src);

        var orig_src = static_optimizer_core_get_original_asset_url(src);

        if ( orig_src ) {
            img_obj.onerror = null;
            img_obj.src = orig_src;
            img_obj.srcset = orig_src;
            console.log("statopt: orig src: " + orig_src);
            console.log(img_obj);
        }

        return true;
    }

    function static_optimizer_handle_broken_script(asset) {
        var src = asset.src || '';
        var orig_src = static_optimizer_core_get_original_asset_url(src);

        if (orig_src) {
            asset.onerror = null;  
            var script = document.createElement("script");
            script.type = "text/javascript";
            script.src = orig_src;
            asset.parentNode.appendChild(script);
        }
        
        return true;
    }

    function static_optimizer_handle_broken_link(asset) {
        var src = asset.href || '';
        var orig_src = static_optimizer_core_get_original_asset_url(src);

        if (orig_src) {
            asset.onerror = null;  
            var link = document.createElement("link");
            link.rel = "stylesheet";
            link.type = "text/css";
            link.media = "all";
            link.href = orig_src;
            document.head.appendChild(link);
        }
    
        return true;
    }

    function static_optimizer_core_get_original_asset_url(src) {
        if (src == '' || src.indexOf('data:') != -1) { // empty or inline src.
            return false;
        }

        if (src.indexOf('.statopt_ver') == -1) { // not optimized.
            console.log("statopt: Skipping item. Not optimized : " + src);
            return false;
        }

        if (src.indexOf(static_optimizer_site_cfg.server_name) == -1) {
            console.log("statopt: Skipping external image: " + src);
            return false;
        }

        if (src.indexOf('/site/http') == -1) { // already linked to the origin src
            console.log("statopt: Skipping already linked to the origin src?: " + src);
            return false;
        }
        
        src = src.replace(/^.+?\/site\/(.*)/ig, '$1');

        // @todo check JSON cfg if htaccess has the handler for statopt_ver installed.
        // script.statopt_ver.5.5.js -> script.statopt_ver.5.5.js
        // script.statopt_ver.5.5.min.js -> script.statopt_ver.5.5.min.js
        // Screenshot-300x150.statopt_ver.5.5.png -> Screenshot-300x150.png
        // Screenshot-300x150.statopt_ver.5.5.5.png -> Screenshot-300x150.png
        // Screenshot-300x150.statopt_ver.1604574710.png -> Screenshot-300x150.png
        // Screenshot-300x150.statopt_ver.sha1-asfoijasofjoiajsfjasfjoasfjioas.png -> Screenshot-300x150.png
        src = src.replace(/statopt_ver[\-_.][\w\-\.]+?\.((min\.)?[a-z]{2,5})$/ig, '$1');

        // https://stackoverflow.com/questions/3431512/javascript-equivalent-to-phps-urldecode
        src = decodeURIComponent(src.replace(/\+/g, ' '));

        // Is the site loaded from https and the asset point to http ?
        if ('https:' == document.location.protocol && src.indexOf('http://') != -1) {
            src = src.replace(/http:\/\//ig, 'https://');
            console.log("statopt: correcting protocol to https: " + src);
        }

        return src;
    }
        </script>
BUFF_EOF;

		return $buff;
	}

	function correctImageSrcsetReplaceCallback( $matches ) {
		$img_html = $matches[0];

		// local or external link
		if (!$this->isInternalSiteLink($img_html)) {
			return $img_html;
		}

		$append_txt = ' onerror="javascript:static_optimizer_handle_broken_image(this);" ';

		if ( stripos( $img_html, 'onerror=' ) === false ) { // the image doesn't have srcset attrib
			$img_html = str_ireplace('<img ', '<img ' . $append_txt, $img_html);
		}

		if ( stripos( $img_html, 'srcset=' ) === false ) { // the image doesn't have srcset attrib
			return $img_html;
		}

		// CSS classes to exclude
		if (strpos($img_html, 'no-lazyload') !== false) {
			return $img_html;
		}

		if ($this->isCDNDomain($img_html)) {
			return $img_html;
		}

		$host = $this->getHost();
		$host_prefix_regex = $this->getHostPrefixRegex();
		$host_q = preg_quote( $host, '#' );

		$buff = preg_replace_callback(
			'#((?:https?://'
			. $host_prefix_regex
			. $host_q
			. '[\w\-/.]*)[:\w\-/.\s%]+?)'
			. '\.(svg|png|jpe?g|gif)(?:\?(?:hash|sha\d+|md5|ts?|version|ver|v|m|_)=([\w\-.]+))?([\'"\s]+)#si',
			[ $this, 'appendQSAssetVerReplaceCallback' ],
			$img_html
		);

		return $buff;
	}

	/**
	 * @param string $buff
	 * @return bool
	 */
	public function isInternalSiteLink($buff ) {
		$host = $this->getHost();
		return stripos( $buff, $host ) !== false;
	}

	public function isCDNDomain($domain ) {
		if (empty($domain)) {  // Too long match
			return true;
		}

		$known_cdns = [
			'googleapis.com',
			's3.amazonaws.com',
			'code.jquery.com',
			'cdnjs.com',
			'cloudflare.com',
			'gravatar.com',
			'jsdelivr.com',
		];

		// Is this a CDN plugin already? yes -> skip it
		foreach ( $known_cdns as $cdn ) {
			if ( stripos( $domain, $cdn ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines if the match should be replaced.
	 * @param array $matches
	 * @return string
	 */
	function appendQSAssetVerReplaceCallback( $matches ) {
		// we'll clear any matches before the path
		$first_match = $matches[1];
		$first_match = trim($first_match, '"\'=. ');

		if ($this->isCDNDomain($first_match)) {
			return $matches[0];
		}

		if (stripos($first_match, '.statopt_ver') !== false) { // the link already has version
			return $matches[0];
		}

		if (!$this->isInternalSiteLink($first_match)) {
			return $matches[0];
		}

		$ver = empty($matches[3]) ? '' : $matches[3];
		$is_ts = strlen($ver) >= 8 && is_numeric($ver) && preg_match('#^\d+$#si', $ver);
		$local_file_regex = '#(/wp-[\w\-]+/.+)#si';
		$clean_no_ver_static_req_uri = $first_match . '.' . $matches[2];

		if ((empty($ver) || !$is_ts) // not already a last mod?
		    && (stripos($clean_no_ver_static_req_uri, 'wp-') !== false) // within wp-
		    && preg_match($local_file_regex, $clean_no_ver_static_req_uri, $local_file_matches) ) {

			$local_file = $this->doc_root . $local_file_matches[1];

			if (file_exists($local_file)) {
				//putenv('QS_APP_SYSTEM_OPTIMIZER_FUNCTION=md5_file');
				//putenv('QS_APP_SYSTEM_OPTIMIZER_CALC_SHARED_FILES_HASH=1');
				$opt_func = getenv('QS_APP_SYSTEM_OPTIMIZER_FUNCTION');
				$calc_hash = getenv('QS_APP_SYSTEM_OPTIMIZER_CALC_SHARED_FILES_HASH');
				$opt_func = empty($opt_func) ? '' : $opt_func;

				// We can do md5_file, sha1_file if necessary but last mod should be good enough and quicker.
				// md5 or sha1 may be useful if we want to make the caching server look up the file by cache id
				// if wp only we'll calc the hash to wp files so there's a chance that the hash caches will be hit more often.
				if (!empty($calc_hash)
				    && preg_match('#/(wp-(includes|admin)|woocommerce|twenty[\w\-]+|divi|generatepress|bootstrap|foundation|oceanwp|avada|storefront|elementor|beaver|composer|ogyxgen|builder|gutenberg)/.+#si', $local_file )) {
					$opt_func = empty($opt_func) ? 'sha1_file' : $opt_func;
				}

				if (empty($opt_func) || !function_exists($opt_func) || !is_callable($opt_func)) {
					$ver = filemtime($local_file);
				} else {
					$ver = call_user_func($opt_func, $local_file);
					$ver = str_replace('_file', '', $opt_func) . '-' . $ver; // put the func before the hash .e.g sha1-aa1111111111
				}
			}
		}

		$ver = empty($ver)? date('Y-m-d') : $ver; // one day caching if version was not found.

		// @todo use https://www.jsdelivr.com/?docs=wp for known wp plugins & themes assets ?
		// ='https://1mapps.qsandbox0.staging.com/statopt/test/site/wp-includes/css/dist/block-library/style.min.statopt_ver.1603969031.css'
		$str = $matches[1] . '.statopt_ver.' . $ver . '.'. $matches[2] . $matches[4];

		$ctx = [
			'url' => $str,
		];

		$optimizer_url = $this->getOptimizerServerUri($ctx);

		if (!empty($optimizer_url)) {
			// get url replace url with server + url and append leave the other stuff as is such as surrounding quotes.
			// ='https://1mapps.qsandbox0.staging.com/statopt/test/site/wp-includes/css/dist/block-library/style.min.statopt_ver.1603969031.css'
			// 1 -> ='
			// 2 -> https://1mapps.qsandbox0.staging.com/statopt/test/site/wp-includes/css/dist/block-library/style.min.statopt_ver.1603969031.css
			// 3 -> '' <= or spaces or quotes
			$r = '#(.*?)(https?://[^\s\'\"]+)(.*)$#si';
			if (preg_match($r, $str, $matches)) {
				$pref = $matches[1];
				$suff = $matches[3];
				$url_only = $matches[2];
				$url_only_esc = urlencode($url_only);

				// Sometimes we may have a specific place where to put the URL as a template variable {url}
				// but if it doesn't exist then we'll just append.
				$search_tpl_var = '{url}';

				if (strpos($optimizer_url, $search_tpl_var) === false) {
					$optimized_asset_url = $optimizer_url . '/site/' . $url_only_esc;
				} else {
					$optimized_asset_url = str_replace($search_tpl_var, $url_only_esc, $optimizer_url);
				}

				$str = $pref . $optimized_asset_url . $suff;
			}
		}

		return $str;
	}

	/**
	 * @return string
	 */
	public function getHost() {
		return $this->host;
	}

	/**
	 * @param string $host
	 */
	public function setHost($host)  {
		$this->host = $host;
	}

	/**
	 * What can a host can be prefixed by. We do allow www prefix for subdomains.
	 * This supports up to 2 levels of subdomain levels.
	 * devel.ca -> croissant.devel.ca
	 * devel.ca -> dev.croissant.devel.ca
	 * This is escaped
	 * @return string
	 */
	public function getHostPrefixRegex() {
		$pref = '(?:www\.)?(?:[a-z\d\-]+\.)?(?:[a-z\d\-]+\.)?';
		return $pref;
	}

	/**
	 * @return string[]
	 */
	public function getServers() {
		static $servers = null;

		if (!is_null($servers)) {
			return $servers;
		}

		$user_defined_servers = '';
		$user_defined_servers_env = getenv('STATIC_OPTIMIZER_SERVERS');

		if (defined('STATIC_OPTIMIZER_SERVERS')) {
			$user_defined_servers = STATIC_OPTIMIZER_SERVERS;
		} elseif (!empty($user_defined_servers_env)) {
			$user_defined_servers = $user_defined_servers_env;
		}

		if (!empty($user_defined_servers)) {
			$servers_arr = preg_split('#[\s,|;]+#si', $user_defined_servers);
			$servers_arr = array_map('trim', $servers_arr);
			$servers_arr = array_filter($servers_arr);
			$servers_arr = array_unique($servers_arr);

			if (!empty($servers_arr)) {
				$servers = $servers_arr;
				return $servers;
			}
		}

		if (!empty($this->cfg['servers'])) { // the user may have custom servers linked to their account
			return $this->cfg['servers'];
		}

		return $this->servers;
	}
}
