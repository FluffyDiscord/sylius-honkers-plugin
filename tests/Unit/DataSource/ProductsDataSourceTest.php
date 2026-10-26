<?php

declare(strict_types=1);

namespace FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\DataSource;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use FluffyDiscord\SyliusHonkersPlugin\Channel\ChannelResolver;
use FluffyDiscord\SyliusHonkersPlugin\Contract\ProductIndexabilityInterface;
use FluffyDiscord\SyliusHonkersPlugin\Contract\ProductViewFactoryInterface;
use FluffyDiscord\Honkers\Cursor\CursorCodec;
use FluffyDiscord\SyliusHonkersPlugin\DataSource\ProductsDataSource;
use FluffyDiscord\SyliusHonkersPlugin\DTO\ProductItem;
use FluffyDiscord\Honkers\DTO\SourceQuery;
use FluffyDiscord\Honkers\Enum\DocumentKind;
use FluffyDiscord\Honkers\Text\HtmlToText;
use FluffyDiscord\SyliusHonkersPlugin\Tests\Unit\Fixtures\RichEditorFactory;
use FluffyDiscord\SyliusHonkersPlugin\Text\RichTextToText;
use MonsieurBiz\SyliusRichEditorPlugin\Twig\RichEditorExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductVariantRepository;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Model\ProductAssociationInterface;
use Sylius\Component\Product\Model\ProductAssociationTypeInterface;
use Sylius\Component\Product\Model\ProductAssociationTypeTranslationInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProductsDataSourceTest extends TestCase
{
    private ?string $capturedDql = null;

    private function createDataSource(
        array $variants,
        ?\Closure $isIndexable = null,
        ?RichEditorExtension $richEditor = null,
    ): ProductsDataSource
    {
        $query = $this->createStub(Query::class);
        $query->method('setParameters')->willReturnSelf();
        $query->method('setFirstResult')->willReturnSelf();
        $query->method('setMaxResults')->willReturnSelf();
        $query->method('getResult')->willReturn($variants);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('createQuery')->willReturnCallback(
            function (string $dql) use ($query): Query {
                $this->capturedDql = $dql;

                return $query;
            },
        );

        $variantRepository = $this->createStub(ProductVariantRepository::class);
        $variantRepository->method('createQueryBuilder')->willReturnCallback(
            fn (string $alias): QueryBuilder => (new QueryBuilder($entityManager))
                ->select($alias)
                ->from(ProductVariant::class, $alias),
        );

        $channelResolver = $this->createStub(ChannelResolver::class);
        $channelResolver->method('getChannel')->willReturn($this->createStub(ChannelInterface::class));

        $productViewFactory = $this->createStub(ProductViewFactoryInterface::class);
        $productViewFactory->method('createItem')->willReturn(new ProductItem(
            'T-SHIRT-01_black',
            'T-SHIRT-01',
            'T-Shirt',
            'https://shop.example/products/t-shirt',
            249900,
            'CZK',
            null,
            true,
        ));

        $productIndexability = $this->createStub(ProductIndexabilityInterface::class);
        $productIndexability->method('isIndexable')->willReturnCallback(
            $isIndexable ?? static fn (): bool => true,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ProductsDataSource(
            $channelResolver,
            $productViewFactory,
            $productIndexability,
            new CursorCodec(),
            new RichTextToText(new HtmlToText(), $richEditor),
            $translator,
            new NullLogger(),
            $variantRepository,
        );
    }

    private function createTaxon(string $code, string $name): TaxonInterface
    {
        $translation = $this->createStub(TaxonTranslationInterface::class);
        $translation->method('getName')->willReturn($name);

        $taxon = $this->createStub(TaxonInterface::class);
        $taxon->method('getCode')->willReturn($code);
        $taxon->method('getTranslation')->willReturn($translation);
        $taxon->method('getParent')->willReturn(null);

        return $taxon;
    }

    /**
     * @param list<ProductAssociationInterface> $associations
     */
    private function createProduct(?string $description = null, array $associations = []): ProductInterface
    {
        $translation = $this->createStub(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn('T-Shirt');
        $translation->method('getShortDescription')->willReturn(null);
        $translation->method('getDescription')->willReturn($description);

        $mainTaxon = $this->createTaxon('CLOTHING', 'Clothing');

        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn(5);
        $product->method('getCode')->willReturn('T-SHIRT-01');
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getAttributesByLocale')->willReturn(new ArrayCollection());
        $product->method('getTaxons')->willReturn(new ArrayCollection([
            $mainTaxon,
            $this->createTaxon('T_SHIRTS', 'T-Shirts'),
        ]));
        $product->method('getMainTaxon')->willReturn($mainTaxon);
        $product->method('getUpdatedAt')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));
        $product->method('getAssociations')->willReturn(new ArrayCollection($associations));

        return $product;
    }

    private function createAssociatedProduct(string $name, ProductVariantInterface $variant): ProductInterface
    {
        $translation = $this->createStub(ProductTranslationInterface::class);
        $translation->method('getName')->willReturn($name);

        $product = $this->createStub(ProductInterface::class);
        $product->method('getTranslation')->willReturn($translation);
        $product->method('getVariants')->willReturn(new ArrayCollection([$variant]));

        return $product;
    }

    /**
     * @param list<ProductInterface> $associatedProducts
     */
    private function createAssociation(string $typeName, array $associatedProducts): ProductAssociationInterface
    {
        $typeTranslation = $this->createStub(ProductAssociationTypeTranslationInterface::class);
        $typeTranslation->method('getName')->willReturn($typeName);

        $type = $this->createStub(ProductAssociationTypeInterface::class);
        $type->method('getTranslation')->willReturn($typeTranslation);
        $type->method('getCode')->willReturn('accessories');

        $association = $this->createStub(ProductAssociationInterface::class);
        $association->method('getType')->willReturn($type);
        $association->method('getAssociatedProducts')->willReturn(new ArrayCollection($associatedProducts));

        return $association;
    }

    private function createVariant(
        int $id = 5,
        ?string $description = null,
        ?ProductInterface $product = null,
        string $code = 'T-SHIRT-01_black',
    ): ProductVariantInterface {
        $translation = $this->createStub(ProductVariantTranslationInterface::class);
        $translation->method('getName')->willReturn(null);

        $variant = $this->createStub(ProductVariantInterface::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getCode')->willReturn($code);
        $variant->method('getProduct')->willReturn($product ?? $this->createProduct($description));
        $variant->method('getTranslation')->willReturn($translation);
        $variant->method('getOptionValues')->willReturn(new ArrayCollection());
        $variant->method('getUpdatedAt')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));

        return $variant;
    }

    public function testDocumentIsKeyedByVariantCodeAndCarriesItsProductCode(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        $document = $page->documents[0];
        self::assertSame('T-SHIRT-01_black', $document->id);
        self::assertSame('T-SHIRT-01_black', $document->metadata['code']);
        self::assertSame('T-SHIRT-01', $document->metadata['productCode']);
    }

    public function testMetadataCarriesTaxonCodesAndNamesAndTheNamesStayInTheText(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount(1, $page->documents);
        $document = $page->documents[0];
        self::assertSame(DocumentKind::Product, $document->kind);
        self::assertSame(['CLOTHING', 'T_SHIRTS'], $document->metadata['taxons']);
        self::assertSame(['Clothing', 'T-Shirts'], $document->metadata['taxonNames']);
        self::assertStringContainsString('Clothing, T-Shirts', $document->text);
        self::assertStringContainsString('Clothing', $document->text);
    }

    public function testMetadataListsOnlyIndexableAssociatedProductsByTypeName(): void
    {
        $sellableBladeVariant = $this->createVariant(6, code: 'BLADE-05_default');
        $disabledClipperVariant = $this->createVariant(7, code: 'CLASS-45_default');
        $associatedProducts = [
            $this->createAssociatedProduct('Blade 0.5 mm', $sellableBladeVariant),
            $this->createAssociatedProduct('Class 45', $disabledClipperVariant),
        ];
        $product = $this->createProduct(associations: [$this->createAssociation('Příslušenství', $associatedProducts)]);
        $dataSource = $this->createDataSource(
            [$this->createVariant(product: $product), $this->createVariant()],
            static fn (ProductVariantInterface $variant): bool => $variant !== $disabledClipperVariant,
        );

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertSame(
            ['Příslušenství' => [['code' => 'BLADE-05_default', 'name' => 'Blade 0.5 mm']]],
            $page->documents[0]->metadata['associations'],
        );
        self::assertArrayNotHasKey('associations', $page->documents[1]->metadata);
    }

    public function testRichEditorDescriptionIsIndexedAsItsRenderedText(): void
    {
        $richEditorDescription = json_encode([
            ['code' => 'monsieurbiz.html', 'data' => ['content' => '<p>Quiet motor, <strong>no vibration</strong>.</p>']],
        ]);
        $variant = $this->createVariant(5, $richEditorDescription);
        $dataSource = $this->createDataSource([$variant], richEditor: (new RichEditorFactory())->create());

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        $text = $page->documents[0]->text;
        self::assertStringContainsString('Quiet motor, no vibration.', $text);
        self::assertStringNotContainsString('monsieurbiz.html', $text);
    }

    public function testDocumentCarriesNoContentHash(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertArrayNotHasKey('contentHash', $page->documents[0]->jsonSerialize());
    }

    public function testIdLookupFiltersByVariantCodeAndIgnoresTheCursor(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $page = $dataSource->getDocuments(new SourceQuery(
            locale: 'cs_CZ',
            cursor: base64_encode('1'),
            ids: ['T-SHIRT-01_black'],
        ));

        self::assertNotNull($this->capturedDql);
        self::assertStringContainsString('variant.code IN (:codes)', $this->capturedDql);
        self::assertStringNotContainsString('variant.id > :lastId', $this->capturedDql);
        self::assertNull($page->nextCursor);
        self::assertCount(1, $page->documents);
    }

    public function testCursorPaginationStillFiltersByLastId(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()]);

        $dataSource->getDocuments(new SourceQuery(locale: 'cs_CZ', cursor: base64_encode('1')));

        self::assertNotNull($this->capturedDql);
        self::assertStringContainsString('variant.id > :lastId', $this->capturedDql);
        self::assertStringNotContainsString('variant.code IN (:codes)', $this->capturedDql);
    }

    public function testNonIndexableVariantsAreSkipped(): void
    {
        $dataSource = $this->createDataSource([$this->createVariant()], static fn (): bool => false);

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertSame([], $page->documents);
    }

    public function testCursorAdvancesFromTheLastFetchedRowEvenWhenItIsNotIndexable(): void
    {
        $pageSize = 200;
        $variants = [];
        for ($id = 1; $id <= $pageSize; ++$id) {
            $variants[] = $this->createVariant($id);
        }

        $lastVariant = $variants[$pageSize - 1];
        $dataSource = $this->createDataSource(
            $variants,
            static fn (ProductVariantInterface $variant): bool => $variant !== $lastVariant,
        );

        $page = $dataSource->getDocuments(new SourceQuery('cs_CZ'));

        self::assertCount($pageSize - 1, $page->documents);
        self::assertSame(base64_encode((string) $pageSize), $page->nextCursor);
    }
}
