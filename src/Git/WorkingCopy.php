<?php

namespace Updatinate\Git;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Updatinate\Util\ExecWithRedactionTrait;

class WorkingCopy implements LoggerAwareInterface
{
    use ExecWithRedactionTrait;
    use LoggerAwareTrait;

    protected $remote;
    protected $remote_fork;
    protected $dir;
    protected $api;

    /**
     * WorkingCopy constructor
     *
     * @param $url Remote origin for the GitHub repository
     * @param $dir Checkout location for the project
     */
    protected function __construct($url, $dir, $branch = false, $api = null)
    {
        $this->remote = new Remote($url);
        $this->remote->addAuthentication($api);
        $this->dir = $dir;
        $this->api = $api;

        $this->confirmCachedRepoHasCorrectRemote();
    }

    public function fromDir($dir, $api = null)
    {
        $this->remote = Remote::fromDir($dir);
        $this->remote->addAuthentication($api);
        $this->dir = $dir;
        $this->api = $api;
    }

    /**
     * addFork will set a secondary remote on this repository.
     * The purpose of having a fork remote is if the primary repository
     * is read-only. If a fork is set, then any branches pushed
     * will go to the fork; any pull request created will still be
     * set on the primary repository, but will refer to the branch on
     * the fork.
     */
    public function addFork($fork_url)
    {
        if (empty($fork_url)) {
            return $this;
        }
        $this->fork = new Remote($fork_url);
        $this->fork->addAuthentication($this->api);
        $php_cookbook->addRemote($this->fork->url(), 'fork');

        return $this;
    }

    /**
     * Clone the specified repository to the given URL at the indicated
     * directory. If the desired repository already exists there, then
     * we will re-use it rather than re-clone the repository.
     *
     * @param string $url
     * @param string $dir
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function clone($url, $dir, $api = null)
    {
        return static::cloneBranch($url, $dir, false, $api);
    }

    /**
     * Clone the specified repository to the given URL at the indicated
     * directory. Only clone a single commit. Since we're only interested
     * in one commit, we'll just remove the cache if it is present.
     *
     * @param string $url
     * @param string $dir
     * @param string $branch
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function shallowClone($url, $dir, $branch, $api = null)
    {
        $workingCopy = new self($url, $dir, $branch, $api);
        $workingCopy->freshClone($branch, $depth);
        return $workingCopy;
    }

    /**
     * Clone the specified branch of the specified repository to the given URL.
     *
     * @param string $url
     * @param string $dir
     * @param string $branch
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function cloneBranch($url, $dir, $branch, $api, $depth = false)
    {
        $workingCopy = new self($url, $dir, $branch, $api);
        $workingCopy->cloneIfNecessary($branch, $depth);
        return $workingCopy;
    }

    /**
     * Blow away the existing repository at the provided directory and
     * force-push the new empty repository to the destination URL.
     *
     * @param string $url
     * @param string $dir
     * @param HubphAPI|null $api
     * @return WorkingCopy
     */
    public static function forceReinitializeFixture($url, $dir, $fixture, $api)
    {
        $fs = new Filesystem();

        // Make extra-sure that no one accidentally calls the tests on a non-fixture repo
        if (strpos($url, 'fixture') === false) {
            throw new \Exception('WorkingCopy::forceReinitializeFixture requires url to contain the string "fixture" to avoid accidental deletion of non-fixture repositories.');
        }

        // TODO: check to see if the fixture repository has never been initialized

        if (false) {
            $auth_url = $api->addTokenAuthentication($url);

            static::copyFixtureOverReinitializedRepo($dir, $fixture);
            exec("git -C {$dir} init", $output, $status);
            exec("git -C {$dir} add -A", $output, $status);
            exec("git -C {$dir} commit -m 'Initial fixture data'", $output, $status);
            static::setRemoteUrl($auth_url, $dir);
            exec("git -C {$dir} push --force origin master");
        }

        $workingCopy = static::clone($url, $dir, $api);

        // Find the first commit and re-initialize
        $topCommit = $workingCopy->git('rev-list HEAD');
        $topCommit = $topCommit[0];
        $firstCommit = $workingCopy->git('rev-list --max-parents=0 HEAD');
        $firstCommit = $firstCommit[0];
        $workingCopy->reset($firstCommit, true);

        // TODO: Not quite working yet; overwrites .git directory even
        // without 'delete' => true
        if (false) {
            // Check to see if the fixtures changed
            // n.b. if we add 'delete' => true then our .git directory
            // disappears, which breaks everything. Without it, we risk
            // retaining deleted assets.
            $fs->mirror($fixture, $dir, null, ['override' => true, 'delete' => true]);
            static::copyFixtureOverReinitializedRepo($dir, $fixture);
            $hasModifications = $workingCopy->status();

            if (!empty($hasModifications)) {
                $workingCopy->add('.');
                $workingCopy->amend();
            }
        }

        $workingCopy->push('origin', 'master', true);

        return $workingCopy;
    }

    /**
     * take tranforms this local working copy such that it RETAINS all of its
     * local files (no change to any unstaged modifications or files) and
     * TAKES OVER the repository from the provided working copy.
     *
     * The local repository that was formerly in place here is disposed.
     * Any branches or commits not already pushed to the remote repository
     * are lost. Only the working files remain. The remotes for this working
     * copy become the remotes from the provided repository.
     *
     * The other working copy is disposed: its files are all removed
     * from the filesystem.
     */
    public function take(WorkingCopy $rhs)
    {
        $fs = new Filesystem();

        $ourLocalGitRepo = $this->dir() . '.git';
        $ourLocalGitRepo = $rhs->dir() . '.git';

        $fs->remove($ourLocalGitRepo);
        $fs->rename($rhsLocalGitRepo, $ourLocalGitRepo);

        $fs->remove($rhs->dir());
    }

    protected static function copyFixtureOverReinitializedRepo($dir, $fixture)
    {
        $fs = new Filesystem();
        $fs->mirror($fixture, $dir, null, ['override' => true, 'delete' => true]);
    }

    public function remote($remote_name = '')
    {
        if (empty($remote_name) || ($remote_name == 'origin')) {
            return $this->remote;
        }
        return Remote::fromDir($this->dir, $remote_name);
    }

    public function url($remote_name = '')
    {
        return $this->remote($remote_name)->url();
    }

    public function dir()
    {
        return $this->dir();
    }

    public function org($remote_name = '')
    {
        return $this->remote($remote_name)->org();
    }

    public function project($remote_name = '')
    {
        return $this->remote($remote_name)->project();
    }

    public function projectWithOrg($remote_name = '')
    {
        return $this->remote($remote_name)->projectWithOrg();
    }

    /**
     * List modified files.
     */
    public function status()
    {
        return $this->git('status --porcelain');
    }

    /**
     * Pull from the specified remote.
     */
    public function pull($remote, $branch)
    {
        $this->git('pull {remote} {branch}', ['remote' => $remote, 'branch' => $branch]);
        return $this;
    }

    /**
     * Push the specified branch to the desired remote.
     */
    public function push($remote = '', $branch = '', $force = false)
    {
        if (empty($remote)) {
            $remote = isset($this->fork) ? 'fork' : 'origin';
        }
        if (empty($branch)) {
            $branch = $this->branch();
        }
        $flag = $force ? '--force ' : '';
        $this->git('push {flag}{remote} {branch}', ['remote' => $remote, 'branch' => $branch, 'flag' => $flag]);
        return $this;
    }

    /**
     * Merge the specified branch into the current branch.
     */
    public function merge($branch)
    {
        $this->git('merge {branch}', ['branch' => $branch]);
        return $this;
    }

    /**
     * Reset to the specified reference.
     */
    public function reset($ref = '', $hard = false)
    {
        $flag = $hard ? '--hard ' : '';
        $this->git('reset {flag}{ref}', ['ref' => $ref, 'flag' => $flag]);
    }

    /**
     * Ensure we are on the correct branch. Update to the
     * latest HEAD from origin.
     */
    public function switchBranch($branch)
    {
        $this->git('checkout {branch}', ['branch' => $branch]);
        return $this;
    }

    /**
     * Create a new branch
     */
    public function createBranch($branch, $base = '', $force = false)
    {
        $flag = $force ? '-B' : '-b';
        $this->git('checkout {flag} {branch} {base}', ['branch' => $branch, 'base' => $base, 'flag' => $flag]);
        return $this;
    }

    /**
     * Stage the items at the specified path.
     */
    public function add($itemsToAdd)
    {
        $this->git('add ' . $itemsToAdd);
        return $this;
    }

    /**
     * Commit the staged changes.
     *
     * @param string $message
     * @param bool $amend
     */
    public function commit($message, $amend = false)
    {
        $flag = $amend ? '--amend ' : '';
        $this->git("commit {flag}-m '{message}'", ['message' => $message, 'flag' => $flag]);
        return $this;
    }

    /**
     * Ammend the top commit without altering the message.
     */
    public function amend()
    {
        return $this->commit($this->message(), true);
    }

    /**
     * Return the commit message for the sprecified ref
     */
    public function message($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('log --format=%B -n 1 {ref}', ['ref' => $ref])));
    }

    public function branch($ref = 'HEAD')
    {
        return trim(implode("\n", $this->git('rev-parse --abbrev-ref {ref}', ['ref' => $ref])));
    }

    /**
     * Show a diff of the current modified and uncommitted files.
     */
    public function diff()
    {
        return trim(implode("\n", $this->git('diff')));
    }

    /**
     * Create a pull request.
     *
     * @param string $message
     * @return $this
     */
    public function pr($message, $body = '', $base = 'master', $head = '')
    {
        if (empty($head)) {
            $head = $this->branch();
        }
        if (isset($this->fork)) {
            $forked_org = $this->fork->org();
            $head = "$forked_org:$head";
        }
        $this->api->prCreate($this->org(), $this->project(), $message, $body, $base, $head);
        return $this;
    }

    /**
     * Show a diff of the specified reference from the commit before it.
     */
    public function show($ref = "HEAD")
    {
        return implode("\n", $this->git("show $ref"));
    }

    /**
     * Add a remote (or change the URL to an existing remote)
     */
    public function addRemote($url, $remote)
    {
        return static::setRemoteUrl($url, $this->dir, $remote);
    }

    /**
     * If the directory exists, check its remote. Fail if there is
     * some project there that is not the requested project.
     */
    protected function confirmCachedRepoHasCorrectRemote($emptyOk = false)
    {
        if (!file_exists($this->dir)) {
            return;
        }
        // Check to see if the remote origin is already set to our exact url
        $currentURL = exec("git -C {$this->dir} config --get remote.origin.url", $output, $result);

        if ($currentURL == $this->url()) {
            return;
        }
        // If the API exists, try to repair the URL if the existing URL is close
        // (e.g. someone switched authentication tokens)
        if ($this->api) {
            if (($emptyOk && empty($currentURL)) || ($this->api->addTokenAuthentication($currentURL) == $this->url())) {
                static::setRemoteUrl($this->url(), $this->dir);
                return;
            }
        }

        // TODO: This error message is a potential credentials leak
        throw new \Exception("Directory `{$this->dir}` exists and is a clone of `$currentURL` rather than `{$this->url()}`");
    }

    /**
     * Set the remote origin to the provided url
     * @param string $url
     * @param string $dir
     * @param string $remote
     */
    protected static function setRemoteUrl($url, $dir, $remote = 'origin')
    {
        if (is_dir($dir)) {
            $currentURL = exec("git -C {$dir} config --get remote.{$remote}.url");
            $gitCommand = empty($currentURL) ? 'add' : 'set-url';
            exec("git -C {$dir} remote {$gitCommand} {$remote} {$url}");
        }
        $remote = new Remote($url);

        return $remote;
    }

    /**
     * If the directory does not exist, then clone it.
     */
    public function cloneIfNecessary($branch = false, $depth = false)
    {
        // If the directory exists, we have already validated that it points
        // at the correct repository.
        if (!is_dir($this->dir)) {
            $this->freshClone($branch, $depth);
        }
        // Make sure that we are on 'master' (or the specified branch) and up-to-date.
        $branchTerm = $branch ?: 'master';
        exec("git -C '{$this->dir}' reset --hard 2>/dev/null", $output, $result);
        exec("git -C '{$this->dir}' checkout $branchTerm 2>/dev/null", $output, $result);
        exec("git -C '{$this->dir}' pull origin $branchTerm 2>/dev/null", $output, $result);
    }

    protected function freshClone($branch = false, $depth = false)
    {
        // Remove $this->dir if it exists, then make sure its parents exist.
        $fs = new Filesystem();
        if (is_dir($this->dir)) {
            $fs->remove($this->dir);
        }
        $fs->mkdir(dirname($this->dir));

        $branchTerm = $branch ? "--branch=$branch " : '';
        $depthTerm = $depth ? "--depth=$depth " : '';
        exec("git clone '{$this->url()}' $branchTerm$depthTerm'{$this->dir}' 2>/dev/null", $output, $result);

        // Fail if we could not clone.
        if ($result) {
            $project = $this->projectWithOrg();
            throw new \Exception("Could not clone $project: git failed with exit code $result");
        }
    }

    /**
     * Run a git function on the local working copy. Fail on error.
     *
     * @return string stdout
     */
    public function git($cmd, $replacements = [], $redacted = [])
    {
        return $this->execWithRedaction('git {dir}' . $cmd, ['dir' => "-C {$this->dir} "] + $replacements, ['dir' => ''] + $redacted);
    }
}
