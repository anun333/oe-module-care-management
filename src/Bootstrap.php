<?php

/**
 * Hooks the module into OpenEMR: a menu entry, its Twig templates, and a card on the
 * patient dashboard.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\CareManagement;

use OpenEMR\Events\Core\TwigEnvironmentEvent;
use OpenEMR\Events\Patient\Summary\Card\SectionEvent;
use OpenEMR\Menu\MenuEvent;
use OpenHealthcare\CareManagement\Card\CareManagementCard;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Loader\FilesystemLoader;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-care-management';
    public const TWIG_NAMESPACE = 'caremanagement';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
        $this->eventDispatcher->addListener(TwigEnvironmentEvent::EVENT_CREATED, $this->addTemplates(...));
        $this->eventDispatcher->addListener(SectionEvent::EVENT_HANDLE, $this->addCard(...));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'cmg';
        $item->menu_id = 'cmg0';
        $item->label = xlt('Care Management');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = ['patients', 'med'];
        $item->global_req = [];

        // After the Staff Worklist if it is there, else after Messages.
        $menu = $event->getMenu();
        $position = count($menu);
        foreach ($menu as $i => $entry) {
            if (in_array($entry->menu_id ?? '', ['wkl0', 'msg0'], true)) {
                $position = $i + 1;
                if (($entry->menu_id ?? '') === 'wkl0') {
                    break;
                }
            }
        }
        array_splice($menu, $position, 0, [$item]);
        $event->setMenu($menu);
        return $event;
    }

    /** The module's templates, under their own namespace (@caremanagement/...) so no core template is shadowed. */
    public function addTemplates(TwigEnvironmentEvent $event): void
    {
        $loader = $event->getTwigEnvironment()->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array(self::TWIG_NAMESPACE, $loader->getNamespaces(), true)) {
            $loader->addPath(dirname(__DIR__) . '/templates', self::TWIG_NAMESPACE);
        }
    }

    public function addCard(SectionEvent $event): void
    {
        if ($event->getSection() !== 'primary') {
            return;
        }
        $pid = (int)($GLOBALS['pid'] ?? 0);
        if ($pid > 0) {
            $event->addCard(new CareManagementCard($pid));
        }
    }
}
