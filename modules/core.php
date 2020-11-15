<?php

$obj = StaticOptimizerCore::getInstance();
add_action( 'init', [ $obj, 'init' ] );

class StaticOptimizerCore extends StaticOptimizerBase {

}
