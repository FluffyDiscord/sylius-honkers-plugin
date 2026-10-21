<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures;

use MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension;
use MonsieurBiz\SyliusRichEditorPlugin\UiElement\Metadata;
use MonsieurBiz\SyliusRichEditorPlugin\UiElement\Registry;
use MonsieurBiz\SyliusRichEditorPlugin\UiElement\UiElement;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RichEditorFactory
{
    public function create(): RichEditorExtension
    {
        $loader = new FilesystemLoader();
        $loader->addPath($this->getRichEditorViewsDirectory(), 'MonsieurBizSyliusRichEditorPlugin');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $app = new AppVariable();
        $app->setRequestStack(new RequestStack());
        $twig->addGlobal('app', $app);

        $registry = new Registry();
        $registry->addUiElement($this->createUiElement('monsieurbiz.html', 'html.html.twig'));
        $registry->addUiElement($this->createUiElement('broken.element', 'missing.html.twig'));

        $richEditor = new RichEditorExtension(
            $registry,
            $twig,
            'monsieurbiz.html',
            'content',
            '/media/',
            sys_get_temp_dir(),
        );
        $twig->addExtension($richEditor);

        return $richEditor;
    }

    private function getRichEditorViewsDirectory(): string
    {
        return dirname(__DIR__, 3) . '/vendor/monsieurbiz/sylius-rich-editor-plugin/src/Resources/views';
    }

    private function getUiElementDirectory(string $area): string
    {
        $sylius2Directory = $area . '/ui_element';
        $isSylius2Layout = is_dir($this->getRichEditorViewsDirectory() . '/' . $sylius2Directory);
        if ($isSylius2Layout) {
            return $sylius2Directory;
        }

        return ucfirst($area) . '/UiElement';
    }

    private function createUiElement(string $code, string $frontTemplate): UiElement
    {
        $adminDirectory = $this->getUiElementDirectory('admin');
        $shopDirectory = $this->getUiElementDirectory('shop');

        $uiElement = new UiElement();
        $uiElement->setMetadata(Metadata::fromCodeAndConfiguration($code, [
            'classes' => ['form' => 'unused'],
            'templates' => [
                'admin_render' => '@MonsieurBizSyliusRichEditorPlugin/' . $adminDirectory . '/html.html.twig',
                'front_render' => '@MonsieurBizSyliusRichEditorPlugin/' . $shopDirectory . '/' . $frontTemplate,
            ],
            'enabled' => true,
        ]));

        return $uiElement;
    }
}
