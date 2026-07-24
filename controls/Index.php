<?php
/** Index — sidecar root (requires the SSO session). Landing while the Workbench
 *  logic is migrated in from core; will redirect to /projects once moved. */
namespace app;
use \Flight as Flight;
use app\BaseControls\Control;
class Index extends Control {
    public function index($params = []) {
        $this->render('index/index', ['title' => 'AI Projects']);
    }
}
