<?php

class Cronjob_Manager extends BuildTask
{

    protected $title = 'Setup and manage cronjobs via web interface';

    protected $description = 'Lists crontab contents and allow adding/managing cronjobs';

    protected $enabled = true;

    public static $silent = true;
    public static $force_run = false;

    // But now it's a buildtask
    public function run($request)
    {
        set_include_path(get_include_path() . PATH_SEPARATOR . BASE_PATH);
        print "<style>*{ font-family: Arial,sans-serif; font-weight: normal; }</style>";

//        print '<iframe style="height:100vh;width:100vw;position:absolute;top:0;left:0;right:0;bottom:0;" src="' . BASE_URL . '/vendor/cronkeep/cronkeep/src/" />';
        print '<iframe style="height:100vh;width:100vw;position:absolute;top:0;left:0;right:0;bottom:0;border:0;" src="' . BASE_URL . '/cronmanager/code/cronkeep/src/" />';

        print 'LOADING...';
    }

}