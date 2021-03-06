<?php
/**
 * Part of the Joomla Tracker View Package
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace JTracker\View\Renderer;

use Adaptive\Diff\Diff;

use App\Tracker\DiffRenderer\Html\Inline;

use Joomla\Cache\Item\Item;
use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;
use Joomla\Http\HttpFactory;

use JTracker\Application;
use JTracker\Authentication\GitHub\GitHubLoginHelper;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension class
 *
 * @since  1.0
 */
class TrackerExtension extends AbstractExtension
{
	/**
	 * Application object
	 *
	 * @var    Application
	 * @since  1.0
	 */
	private $app;

	/**
	 * Cache pool
	 *
	 * @var    CacheItemPoolInterface
	 * @since  1.0
	 */
	private $cache;

	/**
	 * Database connector
	 *
	 * @var    DatabaseDriver
	 * @since  1.0
	 */
	private $db;

	/**
	 * Login helper
	 *
	 * @var    GitHubLoginHelper
	 * @since  1.0
	 */
	private $loginHelper;

	/**
	 * Packages object to look up asset paths
	 *
	 * @var    Packages
	 * @since  1.0
	 */
	private $packages;

	/**
	 * Constructor.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @since   1.0
	 */
	public function __construct(Container $container)
	{
		$this->app         = $container->get('app');
		$this->cache       = $container->get('cache');
		$this->db          = $container->get('db');
		$this->loginHelper = $container->get(GitHubLoginHelper::class);
		$this->packages    = $container->get(Packages::class);
	}

	/**
	 * Returns a list of functions to add to the existing list.
	 *
	 * @return  TwigFunction[]  An array of functions.
	 *
	 * @since   1.0
	 */
	public function getFunctions()
	{
		$functions = [
			new TwigFunction('sprintf', 'sprintf'),
			new TwigFunction('stripJRoot', [$this, 'stripJRoot']),
			new TwigFunction('asset', [$this, 'getAssetUrl']),
			new TwigFunction('avatar', [$this, 'fetchAvatar'], ['is_safe' => ['html']]),
			new TwigFunction('prioClass', [$this, 'getPrioClass']),
			new TwigFunction('priorities', [$this, 'getPriorities']),
			new TwigFunction('getPriority', [$this, 'getPriority']),
			new TwigFunction('status', [$this, 'getStatus']),
			new TwigFunction('getStatuses', [$this, 'getStatuses']),
			new TwigFunction('translateStatus', [$this, 'translateStatus']),
			new TwigFunction('relation', [$this, 'getRelation']),
			new TwigFunction('issueLink', [$this, 'issueLink']),
			new TwigFunction('getRelTypes', [$this, 'getRelTypes']),
			new TwigFunction('getRelType', [$this, 'getRelType']),
			new TwigFunction('getTimezones', [$this, 'getTimezones']),
			new TwigFunction('getContrastColor', [$this, 'getContrastColor']),
			new TwigFunction('renderDiff', [$this, 'renderDiff'], ['is_safe' => ['html']]),
			new TwigFunction('renderLabels', [$this, 'renderLabels']),
			new TwigFunction('arrayDiff', [$this, 'arrayDiff']),
			new TwigFunction('userTestOptions', [$this, 'getUserTestOptions']),
			new TwigFunction('cdn_footer', [$this, 'getCdnFooter'], ['is_safe' => ['html']]),
			new TwigFunction('cdn_menu', [$this, 'getCdnMenu'], ['is_safe' => ['html']]),
		];

		if (!JDEBUG)
		{
			array_push($functions, new TwigFunction('dump', [$this, 'dump']));
		}

		return $functions;
	}

	/**
	 * Returns a list of filters to add to the existing list.
	 *
	 * @return  TwigFilter[]  An array of filters
	 *
	 * @since   1.0
	 */
	public function getFilters()
	{
		return [
			new TwigFilter('basename', 'basename'),
			new TwigFilter('get_class', 'get_class'),
			new TwigFilter('json_decode', 'json_decode'),
			new TwigFilter('stripJRoot', [$this, 'stripJRoot']),
			new TwigFilter('contrastColor', [$this, 'getContrastColor']),
			new TwigFilter('labels', [$this, 'renderLabels']),
			new TwigFilter('yesno', [$this, 'yesNo']),
			new TwigFilter('mergeStatus', [$this, 'getMergeStatus']),
			new TwigFilter('mergeBadge', [$this, 'renderMergeBadge']),
		];
	}

	/**
	 * Replaces the Joomla! root path defined by the constant "JPATH_ROOT" with the string "JROOT".
	 *
	 * @param   string  $string  The string to process.
	 *
	 * @return  mixed
	 *
	 * @since   1.0
	 */
	public function stripJRoot($string)
	{
		return str_replace(JPATH_ROOT, 'JROOT', $string);
	}

	/**
	 * Fetch an avatar.
	 *
	 * @param   string   $userName  The user name.
	 * @param   integer  $width     The with in pixel.
	 * @param   string   $class     The class.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 * @todo    Refactor avatar paths to use the media directory
	 */
	public function fetchAvatar($userName = '', $width = 0, $class = '')
	{
		$base = $this->app->get('uri.base.path');

		$avatar = $userName ? $userName . '.png' : 'user-default.png';

		$width = $width ? ' style="width: ' . $width . 'px"' : '';
		$class = $class ? ' class="' . $class . '"' : '';

		return '<img'
		. $class
		. ' alt="avatar ' . $userName . '"'
		. ' src="' . $base . 'images/avatars/' . $avatar . '"'
		. $width
		. ' />';
	}

	/**
	 * Fetches and renders the CDN footer element, optionally caching the data.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getCdnFooter()
	{
		$key = md5(get_class($this) . '::' . __METHOD__ . ' - language - ' . $this->app->getLanguageTag());

		$fetchPage = function ()
		{
			// Set a very short timeout to try and not bring the site down
			$response = HttpFactory::getHttp()->get(
				'https://cdn.joomla.org/template/renderer.php?section=footer&language=' . $this->app->getLanguageTag(),
				[],
				2
			);

			if ($response->code !== 200)
			{
				return 'Could not load template section.';
			}

			$body = $response->body;

			// Replace the common placeholders
			$body = strtr(
				$body,
				[
					'%reportroute%'  => $this->app->get('uri.base.path') . 'tracker/jtracker/add',
					'%currentyear%' => date('Y'),
				]
			);

			// Replace the context aware placeholders
			if ($this->app->getUser()->id)
			{
				$body = strtr(
					$body,
					[
						'%loginroute%' => $this->app->get('uri.base.path') . 'logout',
						'%logintext%'  => 'Log out',
					]
				);
			}
			else
			{
				$body = strtr(
					$body,
					[
						'%loginroute%' => $this->loginHelper->getLoginUri(),
						'%logintext%'  => 'Log in',
					]
				);
			}

			return $body;
		};

		if ($this->app->get('cache.enabled', false))
		{
			if ($this->cache->hasItem($key))
			{
				$item = $this->cache->getItem($key);

				// Make sure we got a hit on the item, otherwise we'll have to re-cache
				if ($item->isHit())
				{
					$body = $item->get();
				}
				else
				{
					try
					{
						$body = $fetchPage();

						$item = (new Item($key, $this->app->get('cache.lifetime', 900)))
							->set($body);

						$this->cache->save($item);
					}
					catch (\RuntimeException $e)
					{
						$body = 'Could not load template section.';
					}
				}
			}
			else
			{
				try
				{
					$body = $fetchPage();

					$item = (new Item($key, $this->app->get('cache.lifetime', 900)))
						->set($body);

					$this->cache->save($item);
				}
				catch (\RuntimeException $e)
				{
					$body = 'Could not load template section.';
				}
			}
		}
		else
		{
			try
			{
				$body = $fetchPage();
			}
			catch (\RuntimeException $e)
			{
				$body = 'Could not load template section.';
			}
		}

		return $body;
	}

	/**
	 * Fetches and renders the CDN menu element, optionally caching the data.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getCdnMenu()
	{
		$key = md5(get_class($this) . '::' . __METHOD__ . ' - language - ' . $this->app->getLanguageTag());

		$fetchPage = function ()
		{
			// Set a very short timeout to try and not bring the site down
			$response = HttpFactory::getHttp()->get(
				'https://cdn.joomla.org/template/renderer.php?section=menu&language=' . $this->app->getLanguageTag(),
				[],
				2
			);

			if ($response->code !== 200)
			{
				return 'Could not load template section.';
			}

			$body = $response->body;

			// Needless concatenation for PHPCS 150 character line limits...
			$replace = "\t<div id=\"nav-search\" class=\"navbar-search pull-right\">\n\t\t"
				. "<jdoc:include type=\"modules\" name=\"position-0\" style=\"none\" />\n\t</div>\n";

			// Remove the search module
			$body = str_replace($replace, '', $body);

			return $body;
		};

		if ($this->app->get('cache.enabled', false))
		{
			if ($this->cache->hasItem($key))
			{
				$item = $this->cache->getItem($key);

				// Make sure we got a hit on the item, otherwise we'll have to re-cache
				if ($item->isHit())
				{
					$body = $item->get();
				}
				else
				{
					try
					{
						$body = $fetchPage();

						$item = (new Item($key, $this->app->get('cache.lifetime', 900)))
							->set($body);

						$this->cache->save($item);
					}
					catch (\RuntimeException $e)
					{
						$body = 'Could not load template section.';
					}
				}
			}
			else
			{
				try
				{
					$body = $fetchPage();

					$item = (new Item($key, $this->app->get('cache.lifetime', 900)))
						->set($body);

					$this->cache->save($item);
				}
				catch (\RuntimeException $e)
				{
					$body = 'Could not load template section.';
				}
			}
		}
		else
		{
			try
			{
				$body = $fetchPage();
			}
			catch (\RuntimeException $e)
			{
				$body = 'Could not load template section.';
			}
		}

		return $body;
	}

	/**
	 * Get a CSS class according to the item priority.
	 *
	 * @param   integer  $priority  The priority
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getPrioClass($priority)
	{
		switch ($priority)
		{
			case 1 :
				return 'badge-important';

			case 2 :
				return 'badge-warning';

			case 3 :
				return 'badge-info';

			case 4 :
				return 'badge-inverse';

			default :
				return '';
		}
	}

	/**
	 * Get a text list of issue priorities.
	 *
	 * @return  array  The list of priorities.
	 *
	 * @since   1.0
	 */
	public function getPriorities()
	{
		return [
			1 => 'Critical',
			2 => 'Urgent',
			3 => 'Medium',
			4 => 'Low',
			5 => 'Very low',
		];
	}

	/**
	 * Get the priority text.
	 *
	 * @param   integer  $id  The priority id.
	 *
	 * @return string
	 *
	 * @since   1.0
	 */
	public function getPriority($id)
	{
		$priorities = $this->getPriorities();

		return isset($priorities[$id]) ? $priorities[$id] : 'N/A';
	}

	/**
	 * Dummy function to prevent throwing exception on dump function in the non-debug mode.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function dump()
	{
		return;
	}

	/**
	 * Retrieves a human friendly relationship for a given type
	 *
	 * @param   string  $relation  Relation type
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getRelation($relation)
	{
		$relations = [
			'duplicate_of' => 'Duplicate of',
			'related_to'   => 'Related to',
			'not_before'   => 'Not before',
			'pr_for'       => 'Pull Request for',
		];

		return $relations[$relation] ?? '';
	}

	/**
	 * Get a status object based on its id.
	 *
	 * @param   integer  $id  The id
	 *
	 * @return  object
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function getStatus($id)
	{
		static $statuses = [];

		if (!$statuses)
		{
			$items = $this->db->setQuery(
				$this->db->getQuery(true)
					->from($this->db->quoteName('#__status'))
					->select('*')
			)->loadObjectList();

			foreach ($items as $status)
			{
				$status->cssClass = $status->closed ? 'error' : 'success';
				$statuses[$status->id] = $status;
			}
		}

		if (!array_key_exists($id, $statuses))
		{
			throw new \UnexpectedValueException('Unknown status id:' . (int) $id);
		}

		return $statuses[$id];
	}

	/**
	 * Get a text list of statuses.
	 *
	 * @param   int  $state  The state of issue: 0 - open, 1 - closed.
	 *
	 * @return  array  The list of statuses.
	 *
	 * @since   1.0
	 */
	public function getStatuses($state = null)
	{
		switch ((string) $state)
		{
			case '0':
				$statuses = [
					1 => 'New',
					2 => 'Confirmed',
					3 => 'Pending',
					4 => 'Ready To Commit',
					6 => 'Needs Review',
					7 => 'Information Required',
					14 => 'Discussion',
				];
				break;

			case '1':
				$statuses = [
					5 => 'Fixed in Code Base',
					8 => 'Unconfirmed Report',
					9 => 'No Reply',
					10 => 'Closed',
					11 => 'Expected Behaviour',
					12 => 'Known Issue',
					13 => 'Duplicate Report',
				];
				break;

			default:
				$statuses = [
					1 => 'New',
					2 => 'Confirmed',
					3 => 'Pending',
					4 => 'Ready To Commit',
					6 => 'Needs Review',
					7 => 'Information Required',
					14 => 'Discussion',
					5 => 'Fixed in Code Base',
					8 => 'Unconfirmed Report',
					9 => 'No Reply',
					10 => 'Closed',
					11 => 'Expected Behaviour',
					12 => 'Known Issue',
					13 => 'Duplicate Report',
				];
		}

		return $statuses;
	}

	/**
	 * Retrieves the translated status name for a given ID
	 *
	 * @param   integer  $id  Status ID
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function translateStatus($id)
	{
		$statuses = $this->getStatuses();

		return $statuses[$id];
	}

	/**
	 * Get a contrasting color (black or white).
	 *
	 * http://24ways.org/2010/calculating-color-contrast/
	 *
	 * @param   string  $hexColor  The hex color.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getContrastColor($hexColor)
	{
		$r = hexdec(substr($hexColor, 0, 2));
		$g = hexdec(substr($hexColor, 2, 2));
		$b = hexdec(substr($hexColor, 4, 2));
		$yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

		return ($yiq >= 128) ? 'black' : 'white';
	}

	/**
	 * Render a list of labels.
	 *
	 * @param   string  $idsString  Comma separated list of IDs.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function renderLabels($idsString)
	{
		static $labels;

		if (!$labels)
		{
			$labels = $this->app->getProject()->getLabels();
		}

		$html = [];

		$ids = ($idsString) ? explode(',', $idsString) : [];

		foreach ($ids as $id)
		{
			if (array_key_exists($id, $labels))
			{
				$bgColor = $labels[$id]->color;
				$color   = $this->getContrastColor($bgColor);
				$name    = $labels[$id]->name;
			}
			else
			{
				$bgColor = '000000';
				$color   = 'ffffff';
				$name    = '?';
			}

			$html[] = '<span class="label" style="background-color: #' . $bgColor . '; color: ' . $color . ';">';
			$html[] = $name;
			$html[] = '</span>';
		}

		return implode("\n", $html);
	}

	/**
	 * Get HTML for an issue link.
	 *
	 * @param   integer  $number  Issue number.
	 * @param   boolean  $closed  Issue closed status.
	 * @param   string   $title   Issue title.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function issueLink($number, $closed, $title = '')
	{
		$html = [];

		$title = ($title) ? : ' #' . $number;
		$href = $this->app->get('uri')->base->path
			. 'tracker/' . $this->app->getProject()->alias . '/' . $number;

		$html[] = '<a href="' . $href . '" title="' . $title . '">';
		$html[] = $closed ? '<del># ' . $number . '</del>' : '# ' . $number;
		$html[] = '</a>';

		return implode("\n", $html);
	}

	/**
	 * Get relation types.
	 *
	 * @return  array
	 *
	 * @since   1.0
	 */
	public function getRelTypes()
	{
		static $relTypes = [];

		if (!$relTypes)
		{
			$relTypes = $this->db->setQuery(
				$this->db->getQuery(true)
					->from($this->db->quoteName('#__issues_relations_types'))
					->select($this->db->quoteName('id', 'value'))
					->select($this->db->quoteName('name', 'text'))
			)->loadObjectList();
		}

		return $relTypes;
	}

	/**
	 * Get the relation type text.
	 *
	 * @param   integer  $id  The relation id.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getRelType($id)
	{
		foreach ($this->getRelTypes() as $relType)
		{
			if ($relType->value == $id)
			{
				return $this->getRelation($relType->text);
			}
		}

		return '';
	}

	/**
	 * Generate a localized yes/no message.
	 *
	 * @param   integer  $value  A value that evaluates to TRUE or FALSE.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function yesNo($value)
	{
		return $value ? 'Yes' : 'No';
	}

	/**
	 * Get the timezones.
	 *
	 * @return  array  The timezones.
	 *
	 * @since   1.0
	 */
	public function getTimezones()
	{
		return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
	}

	/**
	 * Generate HTML output for a "merge status badge".
	 *
	 * @param   string  $status  The merge status.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function renderMergeBadge($status)
	{
		switch ($status)
		{
			case 'success':
				$class = 'success';
				break;
			case 'pending':
				$class = 'warning';
				break;
			case 'error':
			case 'failure':
				$class = 'important';
				break;

			default:
				throw new \RuntimeException('Unknown status: ' . $status);
		}

		return '<span class="badge badge-' . $class . '">' . $this->getMergeStatus($status) . '</span>';
	}

	/**
	 * Generate a translated merge status.
	 *
	 * @param   string  $status  The merge status.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function getMergeStatus($status)
	{
		switch ($status)
		{
			case 'success':
				return 'Success';

			case 'pending':
				return 'Pending';

			case 'error':
				return 'Error';

			case 'failure':
				return 'Failure';
		}

		throw new \RuntimeException('Unknown status: ' . $status);
	}

	/**
	 * Render the differences between two text strings.
	 *
	 * @param   string   $old              The "old" text.
	 * @param   string   $new              The "new" text.
	 * @param   boolean  $showLineNumbers  To show line numbers.
	 * @param   boolean  $showHeader       To show the table header.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function renderDiff($old, $new, $showLineNumbers = true, $showHeader = true)
	{
		$options = [];

		$renderer = (new Inline)
			->setShowLineNumbers($showLineNumbers)
			->setShowHeader($showHeader);

		return (new Diff(explode("\n", $old), explode("\n", $new), $options))->render($renderer);
	}

	/**
	 * Get the difference of two comma separated value strings.
	 *
	 * @param   string  $a  The "a" string.
	 * @param   string  $b  The "b" string.
	 *
	 * @return string  difference values comma separated
	 *
	 * @since   1.0
	 */
	public function arrayDiff($a, $b)
	{
		$as = explode(',', $a);
		$bs = explode(',', $b);

		return implode(',', array_diff($as, $bs));
	}

	/**
	 * Get a user test option string.
	 *
	 * @param   integer  $id  The option ID.
	 *
	 * @return  mixed array or string if an ID is given.
	 *
	 * @since   1.0
	 */
	public function getUserTestOptions($id = null)
	{
		static $options = [
			0 => 'Not tested',
			1 => 'Tested successfully',
			2 => 'Tested unsuccessfully',
		];

		return ($id !== null && array_key_exists($id, $options)) ? $options[$id] : $options;
	}

	/**
	 * Get the URI for an asset
	 *
	 * @param   string  $path         A public path
	 * @param   string  $packageName  The name of the asset package to use
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getAssetUrl($path, $packageName = null)
	{
		return $this->packages->getUrl($path, $packageName);
	}
}
