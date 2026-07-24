<?php
/** Index — sidecar root → the AI Projects board (requires the SSO session). */
namespace app;
use \Flight as Flight;
use app\BaseControls\Control;
class Index extends Control {
    public function index($params = []) { Flight::redirect('/workbench'); }
}
