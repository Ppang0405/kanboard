<?php

namespace Kanboard\Controller;

use Kanboard\Controller\BaseController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Class CronjobController
 *
 * Runs cron jobs via URL
 *
 * @package Kanboard\Controller
 */
class CronjobController extends BaseController
{
    public function run()
    {
        $this->checkWebhookToken();

        $input = new ArrayInput(array(
            'command' => 'cronjob',
        ));
        $output = new NullOutput();

        $this->cli->setAutoExit(false);
        $exitCode = $this->cli->run($input, $output);

        // Surface console failures (e.g. DB errors) as a non-200 response so
        // URL-based schedulers like the Wasmer Edge job can detect them.
        if ($exitCode !== 0) {
            $this->response->html('Cronjob failed with exit code '.$exitCode, 500);

            return;
        }

        $this->response->html('Cronjob executed');
    }
}
