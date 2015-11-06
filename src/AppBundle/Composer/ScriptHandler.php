<?php

/*
 * This file is part of the Sententiaregum project.
 *
 * (c) Maximilian Bosch <maximilian.bosch.27@gmail.com>
 * (c) Ben Bieler <benjaminbieler2014@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AppBundle\Composer;

use Composer\Script\CommandEvent;
use Sensio\Bundle\DistributionBundle\Composer\ScriptHandler as AbstractScriptHandler;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Handler that runs the npm installation after the composer install.
 *
 * @author Maximilian Bosch <maximilian.bosch.27@gmail.com>
 */
class ScriptHandler extends AbstractScriptHandler
{
    /**
     * Installs the npm dependencies.
     *
     * @param CommandEvent $event
     */
    public static function installNpmDependencies(CommandEvent $event)
    {
        $npm     = (new ExecutableFinder())->find('npm');
        $handler = function ($type, $buffer) use ($event) {
            $event->getIO()->write($buffer, false);
        };

        $process = new Process(sprintf('%s install --no-bin-links', $npm), null, null, null, 1000);
        $process->run($handler);

        $npmScriptName = $event->isDevMode() ? 'build-dev' : 'build';
        $frontendBuild = new Process(sprintf('%s run-script %s', $npm, $npmScriptName), null, null, null, 500);
        $frontendBuild->run($handler);
    }

    /**
     * Loads the doctrine data fixtures (disabled when using the "--no-dev" flag).
     *
     * @param CommandEvent $event
     */
    public static function loadDoctrineDataFixtures(CommandEvent $event)
    {
        if (PreInstallHandler::$firstInstall) {
            if ($event->isDevMode()) {
                static::executeCommand(
                    $event,
                    static::getConsoleDir($event, 'load data fixtures'),
                    'doctrine:fixtures:load --no-interaction'
                );
            } else {
                static::executeCommand(
                    $event,
                    static::getConsoleDir($event, 'load production data fixtures'),
                    'sententiaregum:fixtures:production --no-interaction --env=prod'
                );
            }
        }
    }

    /**
     * Creates the doctrine schema.
     *
     * @param CommandEvent $event
     */
    public static function createDoctrineSchema(CommandEvent $event)
    {
        if (PreInstallHandler::$firstInstall) {
            $envs = $event->isDevMode() ? ['dev', 'test'] : ['prod'];
            self::dropDoctrineSchema($event, $envs);

            foreach ($envs as $env) {
                static::executeCommand(
                    $event,
                    static::getConsoleDir($event, 'create doctrine schema'),
                    sprintf('doctrine:schema:create --env=%s', $env)
                );
            }
        }
    }

    /**
     * Updates the doctrine schema.
     *
     * @param CommandEvent $event
     */
    public static function updateDoctrineSchema(CommandEvent $event)
    {
        static::executeCommand(
            $event,
            static::getConsoleDir($event, 'update doctrine schema'),
            'doctrine:schema:update --force'
        );
    }

    /**
     * Drops the doctrine schema.
     *
     * @param CommandEvent $event
     * @param string[]     $envs
     */
    private static function dropDoctrineSchema(CommandEvent $event, array $envs = [])
    {
        foreach ($envs as $env) {
            static::executeCommand(
                $event,
                static::getConsoleDir($event, 'drop doctrine schema'),
                sprintf('doctrine:schema:drop --force --env=%s', $env)
            );
        }
    }
}
