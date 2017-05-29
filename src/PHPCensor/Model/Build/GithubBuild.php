<?php

namespace PHPCensor\Model\Build;

use PHPCensor\Builder;
use PHPCensor\Helper\Diff;
use PHPCensor\Helper\Github;
use b8\Config;
use b8\HttpClient;
use PHPCensor\Model\BuildError;

/**
 * Github Build Model
 * 
 * @author Dan Cryer <dan@block8.co.uk>
 */
class GithubBuild extends RemoteGitBuild
{
    /**
    * Get link to commit from another source (i.e. Github)
    */
    public function getCommitLink()
    {
        return 'https://github.com/' . $this->getProject()->getReference() . '/commit/' . $this->getCommitId();
    }

    /**
    * Get link to branch from another source (i.e. Github)
    */
    public function getBranchLink()
    {
        return 'https://github.com/' . $this->getProject()->getReference() . '/tree/' . $this->getBranch();
    }

    /**
     * Get link to tag from another source (i.e. Github)
     */
    public function getTagLink()
    {
        return 'https://github.com/' . $this->getProject()->getReference() . '/tree/' . $this->getTag();
    }

    /**
    * Send status updates to any relevant third parties (i.e. Github)
    */
    public function sendStatusPostback()
    {
        $token = Config::getInstance()->get('php-censor.github.token');

        if (empty($token) || empty($this->data['id'])) {
            return;
        }

        $project    = $this->getProject();

        if (empty($project)) {
            return;
        }

        $url    = 'https://api.github.com/repos/'.$project->getReference().'/statuses/'.$this->getCommitId();
        $http   = new HttpClient();

        switch ($this->getStatus()) {
            case 0:
            case 1:
                $status = 'pending';
                $description = 'PHP Censor build running.';
                break;
            case 2:
                $status = 'success';
                $description = 'PHP Censor build passed.';
                break;
            case 3:
                $status = 'failure';
                $description = 'PHP Censor build failed.';
                break;
            default:
                $status = 'error';
                $description = 'PHP Censor build failed to complete.';
                break;
        }

        $url = Config::getInstance()->get('php-censor.url');

        $params = [
            'state'       => $status,
            'target_url'  => $url . '/build/view/' . $this->getId(),
            'description' => $description,
            'context'     => 'PHP Censor',
        ];

        $headers = [
            'Authorization: token ' . $token,
            'Content-Type: application/x-www-form-urlencoded'
        ];

        $http->setHeaders($headers);
        $http->request('POST', $url, json_encode($params));
    }

    /**
    * Get the URL to be used to clone this remote repository.
    */
    protected function getCloneUrl()
    {
        $key = trim($this->getProject()->getSshPrivateKey());

        if (!empty($key)) {
            return 'git@github.com:' . $this->getProject()->getReference() . '.git';
        } else {
            return 'https://github.com/' . $this->getProject()->getReference() . '.git';
        }
    }

    /**
     * Get a parsed version of the commit message, with links to issues and commits.
     * 
     * @return string
     */
    public function getCommitMessage()
    {
        $rtn = parent::getCommitMessage();

        $project = $this->getProject();

        if (!is_null($project)) {
            $reference = $project->getReference();
            $commitLink = '<a target="_blank" href="https://github.com/' . $reference . '/issues/$1">#$1</a>';
            $rtn = preg_replace('/\#([0-9]+)/', $commitLink, $rtn);
            $rtn = preg_replace('/\@([a-zA-Z0-9_]+)/', '<a target="_blank" href="https://github.com/$1">@$1</a>', $rtn);
        }

        return $rtn;
    }

    /**
     * Get a template to use for generating links to files.
     * 
     * @return string
     */
    public function getFileLinkTemplate()
    {
        $reference = $this->getProject()->getReference();
        $branch = $this->getBranch();

        if ($this->getExtra('build_type') == 'pull_request') {
            $matches = [];
            preg_match('/[\/:]([a-zA-Z0-9_\-]+\/[a-zA-Z0-9_\-]+)/', $this->getExtra('remote_url'), $matches);

            $reference = $matches[1];
            $branch = $this->getExtra('remote_branch');
        }

        $link = 'https://github.com/' . $reference . '/';
        $link .= 'blob/' . $branch . '/';
        $link .= '{FILE}';
        $link .= '#L{LINE}-L{LINE_END}';

        return $link;
    }

    /**
     * Handle any post-clone tasks, like applying a pull request patch on top of the branch.
     * @param Builder $builder
     * @param $cloneTo
     * @return bool
     */
    protected function postCloneSetup(Builder $builder, $cloneTo)
    {
        $buildType = $this->getExtra('build_type');

        $success = true;

        try {
            if (!empty($buildType) && $buildType == 'pull_request') {
                $remoteUrl = $this->getExtra('remote_url');
                $remoteBranch = $this->getExtra('remote_branch');

                $cmd = 'cd "%s" && git checkout -b php-censor/' . $this->getId() . ' %s && git pull -q --no-edit %s %s';
                $success = $builder->executeCommand($cmd, $cloneTo, $this->getBranch(), $remoteUrl, $remoteBranch);
            }
        } catch (\Exception $ex) {
            $success = false;
        }

        if ($success) {
            $success = parent::postCloneSetup($builder, $cloneTo);
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function reportError(
        Builder $builder,
        $plugin,
        $message,
        $severity = BuildError::SEVERITY_NORMAL,
        $file = null,
        $lineStart = null,
        $lineEnd = null
    ) {
        $allowCommentCommit      = (boolean)Config::getInstance()->get('php-censor.github.comments.commit', false);
        $allowCommentPullRequest = (boolean)Config::getInstance()->get('php-censor.github.comments.pull_request', false);

        if ($allowCommentCommit || $allowCommentPullRequest) {
            $diffLineNumber = $this->getDiffLineNumber($builder, $file, $lineStart);

            if (!is_null($diffLineNumber)) {
                $helper = new Github();

                $repo     = $this->getProject()->getReference();
                $prNumber = $this->getExtra('pull_request_number');
                $commit   = $this->getCommitId();

                $allowCommentCommit      = (boolean)Config::getInstance()->get('php-censor.github.comments.commit', false);
                $allowCommentPullRequest = (boolean)Config::getInstance()->get('php-censor.github.comments.pull_request', false);

                if (!empty($prNumber)) {
                    if ($allowCommentPullRequest) {
                        $helper->createPullRequestComment($repo, $prNumber, $commit, $file, $diffLineNumber, $message);
                    }
                } else {
                    if ($allowCommentCommit) {
                        $helper->createCommitComment($repo, $commit, $file, $diffLineNumber, $message);
                    }
                }
            }
        }

        parent::reportError($builder, $plugin, $message, $severity, $file, $lineStart, $lineEnd);
    }

    /**
     * Uses git diff to figure out what the diff line position is, based on the error line number.
     * @param Builder $builder
     * @param $file
     * @param $line
     * @return int|null
     */
    protected function getDiffLineNumber(Builder $builder, $file, $line)
    {
        $line = (integer)$line;

        $builder->logExecOutput(false);

        $prNumber = $this->getExtra('pull_request_number');
        $path = $builder->buildPath;

        if (!empty($prNumber)) {
            $builder->executeCommand('cd %s && git diff origin/%s "%s"', $path, $this->getBranch(), $file);
        } else {
            $commitId    = $this->getCommitId();
            $buildSource = $this->getSource();
            $compare     = ($buildSource === self::SOURCE_MANUAL) ? 'HEAD' : $commitId;

            $builder->executeCommand('cd %s && git diff %s^^ "%s"', $path, $compare, $file);
        }

        $builder->logExecOutput(true);

        $diff = $builder->getLastOutput();

        $helper = new Diff();
        $lines = $helper->getLinePositions($diff);

        return isset($lines[$line]) ? $lines[$line] : null;
    }
}
