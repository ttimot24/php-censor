<?php

namespace PHPCensor;

use b8\Store\Factory;
use PHPCensor\Model\Build;

/**
 * PHPCI Build Factory - Takes in a generic "Build" and returns a type-specific build model.
 * 
 * @author Dan Cryer <dan@block8.co.uk>
 */
class BuildFactory
{
    /**
     * @param $buildId
     *
     * @return Build
     *
     * @throws \Exception
     */
    public static function getBuildById($buildId)
    {
        $build = Factory::getStore('Build')->getById($buildId);

        if (empty($build)) {
            throw new \Exception('Build #' . $buildId . ' does not exist in the database.');
        }

        return self::getBuild($build);
    }

    /**
    * Takes a generic build and returns a type-specific build model.
    * @param Build $build The build from which to get a more specific build type.
    * @return Build
    */
    public static function getBuild(Build $build)
    {
        $project = $build->getProject();

        if (!empty($project)) {
            switch ($project->getType()) {
                case 'remote':
                    $type = 'RemoteGitBuild';
                    break;
                case 'local':
                    $type = 'LocalBuild';
                    break;
                case 'github':
                    $type = 'GithubBuild';
                    break;
                case 'bitbucket':
                    $type = 'BitbucketBuild';
                    break;
                case 'bitbuckethg':
                    $type = 'BitbucketHgBuild';
                    break;
                case 'gitlab':
                    $type = 'GitlabBuild';
                    break;
                case 'hg':
                    $type = 'MercurialBuild';
                    break;
                case 'svn':
                    $type = 'SubversionBuild';
                    break;
                case 'gogs':
                    $type = 'GogsBuild';
                    break;
                default:
                    return $build;
            }

            $class = '\\PHPCensor\\Model\\Build\\' . $type;
            $build = new $class($build->getDataArray());
        }

        return $build;
    }
}
