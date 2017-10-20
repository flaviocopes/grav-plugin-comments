<?php
namespace Grav\Plugin;

use Grav\Common\File\CompiledJsonFile;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\User\User;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;

require_once '/html/apps/grav/user/plugins/comments/class/Comment.php';
use Grav\Plugin\Comment;

class CommentsPlugin extends Plugin
{
    protected $route = 'comments';
    protected $enable = false;
    protected $comments_cache_id;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Add the comment form information to the page header dynamically
     *
     * Used by Form plugin >= 2.0
     */
    public function onFormPageHeaderProcessed(Event $event)
    {
        $header = $event['header'];

        if ($this->enable) {
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.comments.form');
            }
        }

        $event->header = $header;
    }

    public function onTwigSiteVariables() {
        $this->grav['twig']->enable_comments_plugin = $this->enable;
        $this->grav['twig']->comments = $this->fetchComments();
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.comments.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.comments.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Frontend side initialization
     */
    public function initializeFrontend()
    {
        $this->calculateEnable();

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->enable) {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 0],
                'onFormProcessed' => ['onFormProcessed', 0],
                'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }

        $cache = $this->grav['cache'];
        $uri = $this->grav['uri'];

        //init cache id
        $this->comments_cache_id = md5('comments-data' . $cache->getKey() . '-' . $uri->url());
    }

    /**
     * Admin side initialization
     */
    public function initializeAdmin()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
        ]);

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        $page = $this->grav['uri']->param('page');
        $comments = $this->getLastComments($page);

        if ($page > 0) {
            echo json_encode($comments);
            exit();
        }

        $this->grav['twig']->comments = $comments;
        $this->grav['twig']->pages = $this->fetchPages();
    }

    /**
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->initializeAdmin();
        } else {
            $this->initializeFrontend();
        }
    }

    /**
     * Handle ajax call.
     */
    public function onPageInitialized()
    {
        // initialize with page settings (post-cache)
//        if (!$this->isAdmin() && isset($this->grav['page']->header()->{'star-ratings'})) {
//			// if not in admin merge potential page-level configs
//            $this->config->set('plugins.star-ratings', $this->mergeConfig($page));
//        }
        $this->callback = 'nested-comments';
//        $this->callback = $this->config->get('plugins.star-ratings.callback');
//        $this->total_stars = $this->config->get('plugins.star-ratings.total_stars');
//        $this->only_full_stars = $this->config->get('plugins.star-ratings.only_full_stars');

        // Process vote if required
        if ($this->callback === $this->grav['uri']->path()) {
            // try to add the vote
            $result = $this->addVote();
            echo json_encode(['status' => $result[0], 'message' => $result[1], 'data' => ['score' => $result[2][0], 'count' => $result[2][1]]]);
            exit();
        }
    }


    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        if (!$this->active) {
            return;
        }

        switch ($action) {
            case 'addComment':
                $post = isset($_POST['data']) ? $_POST['data'] : [];

                $lang = filter_var(urldecode($post['lang']), FILTER_SANITIZE_STRING);
                $path = filter_var(urldecode($post['path']), FILTER_SANITIZE_STRING);
                $text = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
                $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
                $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
                $title = filter_var(urldecode($post['title']), FILTER_SANITIZE_STRING);

                if (isset($this->grav['user'])) {
                    $user = $this->grav['user'];
                    if ($user->authenticated) {
                        $name = $user->fullname;
                        $email = $user->email;
                    }
                }

                /** @var Language $language */
                $language = $this->grav['language'];
                $lang = $language->getLanguage();

				/******************************/
				/** store comments with page **/
				/******************************/
				$page = $this->grav['page'];
				$localfilename = $page->path() . '/comments.yaml';
				$localfile = CompiledYamlFile::instance($localfilename);
                if (file_exists($localfilename)) {
                    $data = $localfile->content();
					$data['autoincrement']++;
                    $data['comments'][] = [
                        'id' => $data['autoincrement'],
                        'parent' => 0,
                        'lang' => $lang,
                        'title' => $title,
                        'text' => $text,
                        'date' => date('D, d M Y H:i:s', time()),
                        'author' => $name,
                        'email' => $email
                    ];
                } else {
                    $data = array(
                        'autoincrement' => 1,
                        'comments' => array([
							'id' => 1,
							'parent' => 0,
							'lang' => $lang,
							'title' => $title,
                            'text' => $text,
                            'date' => date('D, d M Y H:i:s', time()),
                            'author' => $name,
                            'email' => $email
                        ])
                    );
                }
                $localfile->save($data);
				$localid = $data['autoincrement'];
				$data = null;
				/**********************************/
				/** store comments in index file **/
				/**********************************/
				$indexfilename = DATA_DIR . 'comments/index.yaml';
				$indexfile = CompiledYamlFile::instance($indexfilename);
                if (file_exists($indexfilename)) {
                    $data = $indexfile->content();
                    $data['comments'][] = [
                        'page' => $page->route(),
                        'id' => $localid,
                        'parent' => 0,
                        'lang' => $lang,
                        'title' => $title,
                        'text' => $text,
                        'date' => date('D, d M Y H:i:s', time()),
                        'author' => $name,
                        'email' => $email
                    ];
                } else {
                    $data = array(
                        'comments' => array([
							'page' => $page->route(),
							'id' => $localid,
							'parent' => 0,
							'lang' => $lang,
							'title' => $title,
                            'text' => $text,
                            'date' => date('D, d M Y H:i:s', time()),
                            'author' => $name,
                            'email' => $email
                        ])
                    );
                }
                $indexfile->save($data);
				$data = null;
				/**************************************/
				/** store comments in old data files **/
				/** TODO: remove as soon as admin    **/
				/**       panel uses new index file  **/
				/**************************************/
                $filename = DATA_DIR . 'comments';
                $filename .= ($lang ? '/' . $lang : '');
                $filename .= $path . '.yaml';
                $file = CompiledYamlFile::instance($filename);

                if (file_exists($filename)) {
                    $data = $file->content();

                    $data['comments'][] = [
                        'text' => $text,
                        'date' => date('D, d M Y H:i:s', time()),
                        'author' => $name,
                        'email' => $email
                    ];
                } else {
                    $data = array(
                        'title' => $title,
                        'lang' => $lang,
                        'comments' => array([
                            'text' => $text,
                            'date' => date('D, d M Y H:i:s', time()),
                            'author' => $name,
                            'email' => $email
                        ])
                    );
                }

                $file->save($data);

                //clear cache
                $this->grav['cache']->delete($this->comments_cache_id);

                break;
        }
    }

    private function getFilesOrderedByModifiedDate($path = '') {
        $files = [];

        if (!$path) {
            $path = DATA_DIR . 'comments';
        }

        if (!file_exists($path)) {
            Folder::mkdir($path);
        }

        $dirItr     = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filterItr  = new RecursiveFolderFilterIterator($dirItr);
        $itr        = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);

        $itrItr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
        $filesItr = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

        // Collect files if modified in the last 7 days
        foreach ($filesItr as $filepath => $file) {
            $modifiedDate = $file->getMTime();
            $sevenDaysAgo = time() - (7 * 24 * 60 * 60);

            if ($modifiedDate < $sevenDaysAgo) {
                continue;
            }

            $files[] = (object)array(
                "modifiedDate" => $modifiedDate,
                "fileName" => $file->getFilename(),
                "filePath" => $filepath,
                "data" => Yaml::parse(file_get_contents($filepath))
            );
        }

        // Traverse folders and recurse
        foreach ($itr as $file) {
            if ($file->isDir()) {
                $this->getFilesOrderedByModifiedDate($file->getPath() . '/' . $file->getFilename());
            }
        }

        // Order files by last modified date
        usort($files, function($a, $b) {
            return !($a->modifiedDate > $b->modifiedDate);
        });

        return $files;
    }

    private function getLastComments($page = 0) {
        $number = 30;

        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();
        $comments = [];

        foreach($files as $file) {
            $data = Yaml::parse(file_get_contents($file->filePath));

            for ($i = 0; $i < count($data['comments']); $i++) {
                $commentTimestamp = \DateTime::createFromFormat('D, d M Y H:i:s', $data['comments'][$i]['date'])->getTimestamp();

                $data['comments'][$i]['pageTitle'] = $data['title'];
                $data['comments'][$i]['filePath'] = $file->filePath;
                $data['comments'][$i]['timestamp'] = $commentTimestamp;
            }
            if (count($data['comments'])) {
                $comments = array_merge($comments, $data['comments']);
            }
        }

        // Order comments by date
        usort($comments, function($a, $b) {
            return !($a['timestamp'] > $b['timestamp']);
        });

        $totalAvailable = count($comments);
        $comments = array_slice($comments, $page * $number, $number);
        $totalRetrieved = count($comments);

        return (object)array(
            "comments" => $comments,
            "page" => $page,
            "totalAvailable" => $totalAvailable,
            "totalRetrieved" => $totalRetrieved
        );
    }

    /**
     * Return the comments associated to the current route
     */
    private function fetchComments() {
        $cache = $this->grav['cache'];
        //search in cache
        if ($comments = $cache->fetch($this->comments_cache_id)) {
            return $comments;
        }

        $lang = $this->grav['language']->getLanguage();
        $filename = $lang ? '/' . $lang : '';
        $filename .= $this->grav['uri']->path() . '.yaml';

        $comments = $this->getDataFromFilename($filename)['comments'];
		$comments = $this->setCommentLevels($comments);
        //save to cache if enabled
        $cache->save($this->comments_cache_id, $comments);
        return $comments;
    }

    /**
     * Return the latest commented pages
     */
    private function setCommentLevels($comments) {
		$levelsflat = array();
		foreach($comments as $key => $comment) {
			$levelsflat[$comment['id']]['parent'] = $comment['parent'];
			$levelsflat[$comment['id']]['class'] = new Comment($comment['id'], $comments[$key]);
		}
		//get starting points (entries without valid parent = root element)
		$leveltree = array();
		foreach($levelsflat as $id => $parent) {
			$parent_id = $parent['parent'];
			if(!isset($levelsflat[$parent_id])){
				$leveltree[$id] = $levelsflat[$id]['class'];
			} else {
				$currentParent = $levelsflat[$parent_id]['class'];
				$currentChild = $levelsflat[$id]['class'];
				$levelsflat[$id]['class']->setParent($currentParent);
				$levelsflat[$parent_id]['class']->addSubComment($currentChild);
			}
		}
		//reset comment values to nested order
		$comments = array();
		foreach($leveltree as $id => $comment) {
			$comments = array_merge($comments, $comment->getContent());
		}
		return $comments;
    }

    /**
     * Return the latest commented pages
     */
    private function fetchPages() {
        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();

        $pages = [];

        foreach($files as $file) {
            $pages[] = [
                'title' => $file->data['title'],
                'commentsCount' => count($file->data['comments']),
                'lastCommentDate' => date('D, d M Y H:i:s', $file->modifiedDate)
            ];
        }

        return $pages;
    }


    /**
     * Given a data file route, return the YAML content already parsed
     */
    private function getDataFromFilename($fileRoute) {

        //Single item details
        //$fileInstance = CompiledYamlFile::instance(DATA_DIR . 'comments/' . $fileRoute);
		//Use comment file in page folder
		$fileInstance = CompiledYamlFile::instance($this->grav['page']->path() . '/comments.yaml');

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        return $fileInstance->content();
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Add plugin templates path
     */
    public function onTwigAdminTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_COMMENTS.COMMENTS'] = ['route' => $this->route, 'icon' => 'fa-file-text'];
    }

    /**
     * Exclude comments from the Data Manager plugin
     */
    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'comments';
    }
}