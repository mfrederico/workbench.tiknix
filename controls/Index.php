<?php
/** Index — sidecar root → the view this person opens the Builder on: the Task Board, or Ask (requires the SSO session). */
namespace app;
use \Flight as Flight;
use app\BaseControls\Control;
class Index extends Control {
    public function index($params = []) { Flight::redirect(Workbench::preferredView() === 'ask' ? '/workbench/ask' : '/workbench'); }
}
