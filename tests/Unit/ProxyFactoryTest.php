<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\DocumentManager\Tests\Unit;

use PHPCR\NodeInterface;
use Prophecy\Argument;
use ProxyManager\Factory\LazyLoadingGhostFactory;
use ProxyManager\Proxy\LazyLoadingInterface;
use Sulu\Component\DocumentManager\Behavior\Mapping\ParentBehavior;
use Sulu\Component\DocumentManager\Collection\ChildrenCollection;
use Sulu\Component\DocumentManager\DocumentRegistry;
use Sulu\Component\DocumentManager\Event\HydrateEvent;
use Sulu\Component\DocumentManager\Events;
use Sulu\Component\DocumentManager\Metadata;
use Sulu\Component\DocumentManager\MetadataFactoryInterface;
use Sulu\Component\DocumentManager\ProxyFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProxyFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ProxyFactory
     */
    private $factory;

    public function setUp()
    {
        $this->node = $this->prophesize(NodeInterface::class);
        $this->parentNode = $this->prophesize(NodeInterface::class);
        $this->metadataFactory = $this->prophesize(MetadataFactoryInterface::class);
        $this->metadata = $this->prophesize(Metadata::class);
        $this->documentRegistry = $this->prophesize(DocumentRegistry::class);
        $this->dispatcher = $this->prophesize(EventDispatcherInterface::class);
        $this->proxyFactory = new LazyLoadingGhostFactory();
        $this->document = new TestProxyDocument();

        $this->factory = new ProxyFactory(
            $this->proxyFactory,
            $this->dispatcher->reveal(),
            $this->documentRegistry->reveal(),
            $this->metadataFactory->reveal()
        );
    }

    /**
     * It should populate the documents parent property with a proxy.
     */
    public function testCreateProxy()
    {
        $this->documentRegistry->hasNode(Argument::type(NodeInterface::class), 'de')->willReturn(false);
        $this->documentRegistry->getDocumentForNode(Argument::any())->willReturn(new \stdClass());
        $this->documentRegistry->getOriginalLocaleForDocument(Argument::any())->willReturn('de');
        $this->documentRegistry->registerDocument(Argument::cetera())->willReturn(null);
        $this->node->getParent()->willReturn($this->parentNode->reveal());
        $this->metadataFactory->getMetadataForPhpcrNode($this->parentNode->reveal())->willReturn($this->metadata->reveal());
        $this->metadata->getClass()->willReturn(TestProxyDocumentProxy::class);

        $proxy = $this->factory->createProxyForNode($this->document, $this->parentNode->reveal());

        $this->assertInstanceOf(LazyLoadingInterface::class, $proxy);

        return [$this->dispatcher, $proxy];
    }

    /**
     * It should populate the documents parent property (with custom options) with a proxy.
     */
    public function testCreateProxyOptions()
    {
        $options = ['test_option' => 'test'];

        $this->node->getParent()->willReturn($this->parentNode->reveal());
        $this->metadataFactory->getMetadataForPhpcrNode($this->parentNode->reveal())->willReturn($this->metadata->reveal());
        $this->metadata->getClass()->willReturn(TestProxyDocumentProxy::class);

        $proxy = $this->factory->createProxyForNode($this->document, $this->parentNode->reveal(), $options);

        $this->assertInstanceOf(LazyLoadingInterface::class, $proxy);

        $this->dispatcher->dispatch(
            Events::HYDRATE,
            Argument::that(
                function (HydrateEvent $event) use ($options) {
                    return $event->getOptions() === $options;
                }
            )
        )->shouldBeCalled();

        // hydrate
        $proxy->getTitle();
    }

    /**
     * It should lazy load the proxy.
     *
     * @depends testCreateProxy
     */
    public function testHydrateLazyProxy($result)
    {
        list($dispatcher, $proxy) = $result;

        $dispatcher->dispatch(
            Events::HYDRATE,
            Argument::that(
                function (HydrateEvent $arg) {
                    return $arg->getLocale() === 'de';
                }
            )
        )->shouldBeCalled();

        $this->assertEquals('Hello', $proxy->getTitle());
    }

    /**
     * It should create a children node collection.
     */
    public function testCreateChildrenCollection()
    {
        $this->documentRegistry->getNodeForDocument($this->document)->willReturn($this->node->reveal());
        $this->documentRegistry->getOriginalLocaleForDocument($this->document)->willReturn('de');
        $childrenCollection = $this->factory->createChildrenCollection($this->document);

        $this->assertInstanceOf(ChildrenCollection::class, $childrenCollection);
    }
}

class TestProxyDocument implements ParentBehavior
{
    private $parent;

    public function getParent()
    {
        return $this->parent;
    }

    public function setParent($parent)
    {
        $this->parent = $parent;
    }
}

class TestProxyDocumentProxy
{
    public function getTitle()
    {
        return 'Hello';
    }
}
