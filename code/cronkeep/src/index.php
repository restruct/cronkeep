<?php
/**
 * Copyright 2014 Bogdan Ghervan
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

//
// some SS bootstrapping & use Silverstripe to authenticate
//
require_once '../../../../framework/core/Constants.php';
require_once '../../../../framework/core/Core.php';
// setup DB to check if user is logged in
require_once '../../../../framework/model/DB.php';
global $databaseConfig;
if ($databaseConfig) DB::connect($databaseConfig);
// start session
if(!isset($_SESSION) && Session::request_contains_session_id()) {
    Session::start();
}
// check ADMIN privileges
if(!Permission::check('ADMIN')) die('Require ADMIN');
//
// ALL OK: continue with CronKeep code
//

use \models\Crontab;
use \models\Crontab\Exception;
use \models\SystemUser;
use \models\At;
use \forms\AddJob;
use \services\ExpressionService;


$app = new \Slim\Slim([
    'templates.path' => 'application/views',
    'debug' => false
]);

\Slim\Route::setDefaultConditions([
    'hash' => '[a-z0-9]{8}'
]);

// Initialize layout and store it, and use it right away
// as the view for non-XHR requests
$view = new \library\App\Layout();
$view->setTemplatesDirectory($app->config('templates.path'));
$app->config('view', $view);
if (!$app->request->isXhr()) {
    $app->view($view);
}

// Routes
$app->get('/', function() use ($app) {
    $crontab      = new Crontab();
    $systemUser   = new SystemUser();
    $simpleForm   = new AddJob\SimpleForm();
    $advancedForm = new AddJob\AdvancedForm();

    // Some SilverStripe environment vars
    // /usr/bin/php /path/to/silverstripe/docroot/framework/cli-script.php dev/cron
    // For some reason returns /usr/bin/php instead of actual php (/usr/local/bin/php), probably correct $SHELL isn't loaded by php
    $php_path     = exec('which php'); // system PHP, not necessarily the current Apache version, try & find that
    $php_path_version = $php_path;
    if(strpos($php_path, 'bin')>0){
        exec('php -v', $vinfo);
        $php_path_version .= ' [version: ' . array_shift($vinfo) . ']';
    }
    $php_path_info = "System PHP: $php_path_version\n";

    // Too precise, e.g.: /usr/local/Cellar/php56/5.6.27_4/bin (may be different version than CLI linked version
    if(defined('PHP_BINDIR') && PHP_BINDIR) {
        $php_path_webserver = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        $php_path_info .= "\nWebserver PHP: $php_path_webserver \n(if this contains version numbers they may change at updates, may not be suitable for use in cronjob)\n";
    }

    // May cause trouble with open_basedir...
    // Not always defined (not defined in my dev environment, neither in live)
//    if(defined('PHP_BINARY') && PHP_BINARY) die(PHP_BINARY); $php_full_path = PHP_BINARY;
    // Try & add extra info
    $usr_local_bin = '/usr/local/bin/php';
    // @is_readable to prevent warning in case out of open_basedir
    if(@is_readable($usr_local_bin) && file_exists($usr_local_bin) && $usr_local_bin_target = readlink($usr_local_bin)){
        $php_path_info .= "\nAlias: '$usr_local_bin' may be good to use, links to: $usr_local_bin$usr_local_bin_target\n";
        $php_path = $usr_local_bin; // reset if exists
    }

    $showAlertAtUnavailable = $app->getCookie('showAlertAtUnavailable');
    $app->view->setData('showAlertAtUnavailable', $showAlertAtUnavailable !== null ?
        (bool) $showAlertAtUnavailable : true);

    $app->render('index.phtml', [
        'crontab'              => $crontab,
        'systemUser'           => $systemUser,

        'simpleForm'           => $simpleForm,
        'advancedForm'         => $advancedForm,
        // For SS/PHP environment info (used via ssenv-info.phtml partial)
        'phpPath'              => $php_path,
        'phpPathInfo'          => $php_path_info,
        'ssCliScriptPath'      => FRAMEWORK_PATH . '/cli-script.php',
        'ssEnvHostSetup'       => Director::protocolAndHost(),
    ]);
});

/**
 * Groups cron job related routes.
 */
$app->group('/job', function() use ($app) {
    /**
     * Should be used as a route middleware to allow for the response
     * to be JSON in the route's callable.
     *
     * @return void
     */
    $setupJsonResponse = function() {
        $app = \Slim\Slim::getInstance();
        $app->add(new \SlimJson\Middleware());
    };

    /**
     * Adds or edits a cron job.
     */
    $app->post('/save', $setupJsonResponse, function() use ($app) {
        $formData = $app->request->post();
        $hash = $app->request->params('hash');

        $form = AddJob\FormFactory::createForm($formData);
        if ($form->isValid()) {
            $crontab = new Crontab();

            if ($formData['mode'] == AddJob\FormFactory::SIMPLE) {
                $expression = ExpressionService::createExpression($formData);
            } else {
                $expression = $formData['expression'];
            }

            // This is an edit
            if ($hash) {
                $job = $crontab->findByHash($hash);
                if (!$job) {
                    $app->render(500, [
                        'error' => true,
                        'msg' => 'Cron job no longer exists'
                    ]);
                    $app->stop();
                }
            } else {
                $job = new Crontab\Job();
            }

            $job->setExpression($expression);
            $job->setCommand($formData['command']);
            $job->setComment($formData['name']);

            if ($hash) {
                $crontab->update($job);
            } else {
                $crontab->add($job);
            }
            $crontab->save();

            $response = [
                'error' => false,
                'msg' => 'The job has been saved.',
                'hash' => $job->getHash()
            ];
            if ((bool) $formData['returnHtml']) {
                $response['html'] = $app->config('view')->partial('partials/job.phtml', [
                    'job' => $job
                ]);
            }

            $app->render(200, $response);
        } else {
            $app->render(500, [
                'error' => true,
                'msg' => $form->getFormattedMessages()
            ]);
        }
    });

    $app->get('/edit-form/:hash', $setupJsonResponse, function($hash) use ($app) {
        $crontab = new Crontab();
        $job = $crontab->findByHash($hash);
        if (!$job) {
            $app->render(404, [
                'error' => true,
                'msg' => 'Cron job no longer exists'
            ]);
            $app->stop();
        }

        $expressionService = new ExpressionService();

        // Prepare simple form
        $simpleForm = null;
        if ($expressionService->isSimpleExpression($job->getExpression())) {
            $simpleForm = new AddJob\SimpleForm();
            $simpleForm->get('name')->setValue($job->getComment());
            $simpleForm->get('command')->setValue($job->getCommand());
            $expressionService->hydrateSimpleForm($job->getExpression(), $simpleForm);
        }

        // Prepare advanced form
        $advancedForm = new AddJob\AdvancedForm();
        $advancedForm->get('name')->setValue($job->getComment());
        $advancedForm->get('command')->setValue($job->getCommand());
        $advancedForm->get('expression')->setValue($job->getExpression());

        $app->render(200, [
            'error' => false,
            'html'  => $app->config('view')->partial('partials/job-edit-dialog.phtml', [
                'hash'           => $hash,
                'simpleForm'   => $simpleForm,
                'advancedForm' => $advancedForm
            ])
        ]);
    });

    /**
     * Runs a cron job in background.
     */
    $app->get('/run/:hash', $setupJsonResponse, function($hash) use ($app) {
        $crontab = new Crontab();
        $job = $crontab->findByHash($hash);

        if ($job) {
            $crontab->run($job);

            $app->render(200, [
                'error' => false,
                'msg' => 'Process started: '
            ]);
        } else {
            $app->render(404, [
                'error' => true,
                'msg' => 'Cron job no longer exists'
            ]);
        }
    });

    /**
     * Pauses schedule by commenting the job in crontab, so it no longer runs when
     * is is supposed to.
     */
    $app->get('/pause/:hash', $setupJsonResponse, function($hash) use ($app) {
        $crontab = new Crontab();
        $job = $crontab->findByHash($hash);

        if ($job) {
            $crontab->pause($job)->save();

            $app->render(200, [
                'error' => false,
                'msg' => 'Job schedule has been paused.',
                'hash' => $job->getHash()
            ]);
        } else {
            $app->render(404, [
                'error' => true,
                'msg' => 'Cron job no longer exists'
            ]);
        }
    });

    /**
     * Resumes schedule by un-commenting the job in crontab.
     */
    $app->get('/resume/:hash', $setupJsonResponse, function($hash) use ($app) {
        $crontab = new Crontab();
        $job = $crontab->findByHash($hash);

        if ($job) {
            $crontab->resume($job)->save();

            $app->render(200, [
                'error' => false,
                'msg' => 'Job schedule has been resumed.',
                'hash' => $job->getHash()
            ]);
        } else {
            $app->render(404, [
                'error' => true,
                'msg' => 'Cron job no longer exists.'
            ]);
        }
    });

    /**
     * Deletes job from crontab.
     */
    $app->get('/delete/:hash', $setupJsonResponse, function($hash) use ($app) {
        $crontab = new Crontab();
        $job = $crontab->findByHash($hash);

        if ($job) {
            $crontab->delete($job)->save();

            $app->render(200, [
                'error' => false,
                'msg' => 'Job has been deleted.'
            ]);
        } else {
            $app->render(404, [
                'error' => true,
                'msg' => 'Cron job no longer exists.'
            ]);
        }
    });

    /**
     * Error handler for job methods.
     */
    $app->error(function (\Exception $e) use ($app) {
        $app->render(500, [
            'error' => true,
            'msg'   => $e->getMessage()
        ]);
    });
});

/**
 * Global error handler.
 */
$app->error(function (\Exception $e) use ($app) {
    $template = 'partials/alerts/unknown-error.phtml';
    switch (true) {
        case ($e instanceof Exception\SpoolUnreachableException):
            $template = 'partials/alerts/spool-unreachable.phtml';
            break;
        case ($e instanceof Exception\PamUnreadableException):
            $template = 'partials/alerts/pam-unreadable.phtml';
            break;
    }

    $app->render($template, ['e' => $e]);
});

$app->run();
