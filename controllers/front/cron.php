<?php
/**
 * 2007-2016 PrestaShop
 *
 * thirty bees is an extension to the PrestaShop e-commerce software developed by PrestaShop SA
 * Copyright (C) 2017-2024 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2024 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   https://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  PrestaShop is an internationally registered trademark & property of PrestaShop SA
 */

use GuzzleHttp\Client;

if (!defined('_TB_VERSION_')) {
    if (php_sapi_name() !== 'cli') {
        exit;
    } else {
        require_once __DIR__.'/../../../../config/config.inc.php';
    }
}

/**
 * Class AdminCronJobsController
 */
class CronJobscronModuleFrontController extends ModuleFrontController
{
    /**
     * @var CronJobs $module
     */
    public $module;

    /**
     * @return bool
     * @throws PrestaShopException
     */
    public function checkAccess()
    {
        if (Tools::getValue('token') != Configuration::getGlobalValue(CronJobs::EXECUTION_TOKEN)) {
            die('Invalid token');
        }
        return true;
    }


    /**
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        Configuration::updateGlobalValue(CronJobs::LAST_EXECUTION, time());

        $this->module->sendCallback();

        ob_start();

        $this->runModulesCrons();
        $this->runTasksCrons();

        ob_end_clean();

        exit;
    }

    /**
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function runModulesCrons()
    {
        $crons = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from(CronJobs::TABLE)
                ->where('`active` = 1')
                ->where('`id_module` IS NOT NULL')
        );

        if (is_array($crons) && (count($crons) > 0)) {
            foreach ($crons as $cron) {
                $moduleId = (int)$cron['id_module'];
                $cronjobId = (int)$cron['id_cronjob'];
                $module = Module::getInstanceById($moduleId);
                $description = $cron['description'];

                if (!$module) {
                    Db::getInstance()->delete(CronJobs::TABLE, '`id_cronjob` = ' . $cronjobId);
                    break;
                } elseif ($this->shouldBeExecuted($cron)) {
                    try {
                        Hook::exec('actionCronJob', [], $moduleId);
                    } catch (Throwable $e) {
                        $this->processError($cronjobId, $description, $e);
                    } finally {
                        $this->markAsExecuted($cronjobId);
                    }
                }
            }
        }
    }

    /**
     * @param array $cron
     *
     * @return bool
     */
    protected function shouldBeExecuted($cron)
    {
        $minute = ($cron['minute'] == -1) ? date('i') : $cron['minute'];
        $hour = ($cron['hour'] == -1) ? date('H') : $cron['hour'];
        $day = ($cron['day'] == -1) ? date('d') : $cron['day'];
        $month = ($cron['month'] == -1) ? date('m') : $cron['month'];
        $dayOfWeek = ($cron['day_of_week'] == -1) ? date('D') : date('D', strtotime('Sunday +'.$cron['day_of_week'].' days'));

        $day = date('Y').'-'.str_pad($month, 2, '0', STR_PAD_LEFT).'-'.str_pad($day, 2, '0', STR_PAD_LEFT);
        $execution = $dayOfWeek.' '.$day.' '.str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);
        $now = date('D Y-m-d H:i');

        return !strcmp($now, $execution);
    }

    /**
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function runTasksCrons()
    {
        $crons = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from(CronJobs::TABLE)
                ->where('`active` = 1')
                ->where('`id_module` IS NULL')
        );

        $guzzle = new Client(['timeout' => 10000000]);

        if (is_array($crons) && (count($crons) > 0)) {
            foreach ($crons as $cron) {
                if ($this->shouldBeExecuted($cron)) {
                    $cronjobId = (int)$cron['id_cronjob'];
                    $description = $cron['description'];
                    $url = urldecode($cron['task']);
                    try {
                        $guzzle->get($url);
                    } catch (Throwable $e) {
                        $this->processError($cronjobId, $description . " [$url]", $e);
                    } finally {
                        $this->markAsExecuted($cronjobId);
                    }
                }
            }
        }
    }

    /**
     * Marks cron job as executed
     *
     * @param int $cronjobId
     *
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function markAsExecuted($cronjobId)
    {
        $cronjobId = (int)$cronjobId;
        Db::getInstance()->update(
            CronJobs::TABLE,
            [
                'updated_at' => ['type' => 'sql', 'value' => 'NOW()'],
                'active' => ['type' => 'sql', 'value' => 'IF (`one_shot` = TRUE, FALSE, `active`)'],
            ],
            '`id_cronjob` = ' . $cronjobId
        );
    }

    /**
     * Logs errors into /log/cronjobs_<date>_exception.log
     *
     * @param int $cronjobId
     * @param string $description
     * @param Throwable $e
     *
     * @return void
     */
    protected function processError(int $cronjobId, string $description, Throwable $e)
    {
        // log to error log
        $logger = new FileLogger();
        $logger->setFilename(_PS_ROOT_DIR_ . '/log/cronjobs_' . date('Ymd') . '_exception.log');
        $logger->logError($description . ": " . $e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine());

        // pass through thirty bees error handler, if exists
        if (class_exists('\Thirtybees\Core\Error\ErrorUtils')) {
            $errorDescription = \Thirtybees\Core\Error\ErrorUtils::describeException($e);
            $serviceLocator = \Thirtybees\Core\DependencyInjection\ServiceLocator::getInstance();
            $serviceLocator->getErrorHandler()->logFatalError($errorDescription);
        }
    }
}

// dispatch controller if in CLI mode
if (php_sapi_name() === 'cli') {
    $first = true;
    foreach ($argv as $arg) {
        if ($first) {
            $first = false;
            continue;
        }

        // process arguments that starts with --
        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);
            $e = explode('=', $arg);
            $argName = $e[0];
            if ($argName) {
                if (count($e) == 2) {
                    $_GET[$argName] = $e[1];
                } else {
                    $_GET[$argName] = true;
                }
            }
        }
    }
    $_GET['module'] = 'cronjobs';
    $_GET['fc'] = 'module';
    $_GET['controller'] = 'cron';

    /** @noinspection PhpUnhandledExceptionInspection */
    Dispatcher::getInstance()->dispatch();
}
