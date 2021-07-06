=== StaticOptimizer ===
Contributors: lordspace,statopt,orbisius
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=7APYDVPBCSY9A
Tags: wp,static,optimization,smush,ewww,autoptimize,cache,minify,caching,speed,performance,supercacher,wp-super-cache,wp-fastest-cache,image,asset,js,css,optimisation
Requires at least: 4.0
Tested up to: 5.7
Requires PHP: 5.6
Stable tag: 1.0.5
License: GPLv2 or later

Optimizes your assets (images, css, js) for faster site loading so they are loaded by StaticOptimizer Optimization servers.

== Description ==

StaticOptimizer is a static file optimization cloud service that speeds up your site by optimizing and serving (the selected) static files from your site.
The service optimizes & compresses the files automatically.
No database or files changes are performed therefore there's no risk if you want to uninstall the plugin later (... but why would you?)
If our optimization server are down (upgrade, maintenance or outage) your original files will be loaded instead.

= Features / Benefits =
* Simple, efficient and free to use
* The free plan allows you to have images up to 5mb and up to 1600px wide or tall
* Easy to set up. Just get your API key and you're good to go.
* Automatic js and css minification & compression
* Automatic image optimization (gif, jpeg, png, svg)
* Files are reloaded only when they are changed on your server (or deleted from ours).
* Automatic conversion to webp
* Our servers check and if the visitor's browser supports webp we'll serve that file otherwise the optimized version.
* Responsive Images (from srcset) are also processed
* Background images url() are also processed
* We've put extra efforts to make this plugin as efficient as possible.
* The code in script blocks is preserved
* Sandbox/test/development subdomains are automatically authorized if the main domain is authorized. Test subdomains are: test, testing, sandbox, dev, local, stage, staging
* SEO: Our servers return canonical header to tell which is the source file. Link: <https://example.com/images/image.png>; rel="canonical"
* Select if you want your site to be optimized by servers in Europe or North America
* No lock-in = after deactivation, all files will work and load from your current host and no data loss

== Demo ==

Test drive
https://wpdemo.net/try/plugin/static-optimizer

Video demo
https://www.youtube.com/watch?v=1KC_JJOcu1s

= How it works =

After you get your API key the plugin will install as a system (mu-plugin) and will correct the location where the selected files are loaded from.
During the plugin configuration you can tell the plugin which file types you'd like to be optimized.
By default only images are selected for optimization. You can also select js, & css files to be optimized as well.
The first time our servers get a file it will be downloaded and optimized. Because this operation can take several seconds
your users will be redirected back to the original file that was requested. Any subsequent requests will be served from our servers.

= Who is this plugin for? =
People who have lots of images on their site such online stores, photographers, real estate agents.

= Thanks =

The logo was designed by Atanas Lambev - <a href="https://dralart.com" title="Web Design Bulgaria, Logo design bulgaria" target="_blank">https://dralart.com</a>
	
I'd like to express my gratitude to the people who have taken the time to test the plugin & make suggestions and/or has provided access to troubleshoot glitches.
* Phil Ryan - <a href="https://site123.ca" title="" target="_blank">https://site123.ca</a>
* Michel Veenstra - <a href="https://www.adventis.nl" title="" target="_blank">https://www.adventis.nl</a>

* and to many more.

== Support ==

Bugs? Suggestions? If you want a faster response contact us through our website's contact form at https://statopt.com and 
not through the support tab of this plugin or WordPress forums for a faster response.

= Author =

Svetoslav Marinov (Slavi) | <a href="https://statopt.com/?utm_source=static-optimizer-wp" title="Image, js, css optimization and compression" target="_blank">StaticOptimizer</a>

== Upgrade Notice ==
n/a

== Screenshots ==
1. Showing the attachments listing page
2. Showing the result

== Installation ==

1. Unzip the package, and upload `static-optimizer` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure which file types you'd like to get optimized
4. Create a free account and pick a plan
5. Done

== Frequently Asked Questions ==

= For the most up-to-date FAQ see the site =

Check out <a href="https://statopt.com" target="_blank" title="[new window]">https://statopt.com</a>

= Bug Report / Feature Requests =

Please submit any bug report or feature requests in our github issue tracking system.
<a href="https://github.com/statopt/static-optimizer-wp/issues" target="_blank" title="[new window]">Report Bugs / Features</a>

= Does it work with WordPress multisite? =
Yes

== Changelog ==

= 1.0.5 =
* added optimization support for svg images
* the CSS relative paths should have been resolved.
* added demo
* todo: show a notice if the domain name has changed & for sandbox
* added a parameter 'statopt_status' to turn off the optimization for a given page using ?statopt_status=0
* added a fallback parameter if for some reason the replacements didn't succeed.
* Tested with WP 5.7

= 1.0.4 =
* Fix: when js/css couldn't be loaded we used to create a new obj. Now we are reusing the existing one.
* Pick preferred server location in Europe or North America
* Refactored code so it uses OOP style.
* Fixed sha1 wasn't calculated for the most common files
* Fixed images file type was unselectable.
* Fixed the link to the quick contact page
* Cleaned up code. Removed old code that was going to be used for the mu plugin
* Added an option so people can link back to StaticOptimizer home page.

= 1.0.3 =
* moved demo up
* readme changes
* removed the fonts from the selected list as they may not load all the time usually those with relative paths
* added a check so the api form expands after the user submits the form to get a key
* added notes about fonts referenced/loaded via relative paths
* fixed the notice that's shown on localhost installs
* improved image parser to skip external images.
* added a fix for the parser that deals with backgrounds with url() ... it was capturing the last closing parenthesis

= 1.0.2 =
* Removed the check for body start which was making the plugin to not process anything pretty early. On some sites the body started after 60-70kb
* fixed twitter handle to statopt
* hide the settings form when there's no api key so it's easier to see what's expected from the user.

= 1.0.1 =
* Updated text & labels

= 1.0.0 =
* Initial release
