<?php

namespace Doctrine\Tests\ODM\PHPCR\Functional\Tools\Helper;

use Doctrine\ODM\PHPCR\DocumentManagerInterface;
use Doctrine\ODM\PHPCR\Tools\Helper\TranslationConverter;
use Doctrine\ODM\PHPCR\Translation\LocaleChooser\LocaleChooser;
use Doctrine\ODM\PHPCR\Translation\TranslationStrategy\AttributeTranslationStrategy;
use Doctrine\Tests\Models\Blog\Comment;
use Doctrine\Tests\Models\Translation\Article;
use Doctrine\Tests\Models\Translation\ChildTranslationArticle;
use Doctrine\Tests\Models\Translation\ChildTranslationComment;
use Doctrine\Tests\Models\Translation\Comment as TranslatedComment;
use Doctrine\Tests\ODM\PHPCR\Functional\Translation\AttributeTranslationStrategyTest;
use Doctrine\Tests\ODM\PHPCR\PHPCRFunctionalTestCase;
use PHPCR\NodeInterface;
use PHPCR\SessionInterface;

class TranslationConverterTest extends PHPCRFunctionalTestCase
{
    /**
     * @var DocumentManagerInterface
     */
    private $dm;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var NodeInterface Root node for functional test
     */
    private $node;

    /**
     * @var TranslationConverter
     */
    private $converter;

    private $localePrefs = [
        'en' => ['de'],
        'de' => ['en'],
    ];

    public function setUp()
    {
        $this->dm = $this->createDocumentManager();
        $this->dm->setLocaleChooserStrategy(new LocaleChooser($this->localePrefs, 'en'));
        $this->node = $this->resetFunctionalNode($this->dm);
        $this->session = $this->node->getSession();

        $this->converter = new TranslationConverter($this->dm);
    }

    /**
     * Also test batch functionality. The other tests don't cover batching.
     */
    public function testTranslateAttribute()
    {
        $this->converter = new TranslationConverter($this->dm, 1);

        $class = TranslatedComment::class;
        $field = 'text';
        $comment = $this->node->addNode('convert');
        $comment->setProperty($field, 'Lorem ipsum...');
        $comment->setProperty('phpcr:class', $class);
        $comment = $this->node->addNode('convert2');
        $comment->setProperty($field, 'Lorem ipsum...');
        $comment->setProperty('phpcr:class', $class);
        $this->session->save();

        $this->assertTrue($this->converter->convert($class, ['en']));
        $this->session->save();
        $this->assertTrue($this->converter->convert($class, ['en']));
        $this->session->save();
        $this->assertFalse($this->converter->convert($class, ['en']));
        $this->session->save();

        $this->assertTrue(
            $comment->hasProperty(
                AttributeTranslationStrategyTest::propertyNameForLocale('en', $field)
            ),
            'new property was not created'
        );
        $this->assertFalse($comment->hasProperty($field), 'old property was not removed');

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->getText());

        $this->dm->clear();

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->getText());
    }

    /**
     * Test when a document already had translated fields.
     */
    public function testPartialTranslateAttribute()
    {
        $article = new Article();
        $article->id = '/functional/convert';
        $article->topic = 'Some interesting subject';
        $article->setText('Lorem ipsum...');
        $this->dm->persist($article);
        $this->dm->flush();
        $this->dm->clear();

        $class = Article::class;
        $field = 'nullable';
        $comment = $this->node->getNode('convert');
        $comment->setProperty($field, 'Move to translated');
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en'], [$field]));
        $this->session->save();
        $this->dm->clear();

        $this->assertTrue(
            $comment->hasProperty(
                AttributeTranslationStrategyTest::propertyNameForLocale('en', $field)
            ),
            'new property was not created'
        );
        $this->assertFalse($comment->hasProperty($field), 'old property was not removed');

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to translated', $article->nullable);
        $this->assertEquals('Lorem ipsum...', $article->getText());

        $this->dm->clear();

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to translated', $article->nullable);
        $this->assertEquals('Lorem ipsum...', $article->getText());
    }

    /**
     * To demonstrate what happens when the fields parameter is omitted.
     */
    public function testPartialTranslateAttributeErase()
    {
        $article = new Article();
        $article->id = '/functional/convert';
        $article->topic = 'Some interesting subject';
        $article->setText('Lorem ipsum...');
        $this->dm->persist($article);
        $this->dm->flush();
        $this->dm->clear();

        $class = Article::class;
        $field = 'nullable';
        $comment = $this->node->getNode('convert');
        $comment->setProperty($field, 'Move to translated');
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en']));
        $this->session->save();
        $this->dm->clear();

        $this->assertTrue(
            $comment->hasProperty(
                AttributeTranslationStrategyTest::propertyNameForLocale('en', $field)
            ),
            'new property was not created'
        );
        $this->assertFalse($comment->hasProperty($field), 'old property was not removed');

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to translated', $article->nullable);
        $this->assertNull($article->getText()); // we lost this because we did not specify to only convert $field

        $this->dm->clear();

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to translated', $article->nullable);
        $this->assertNull($article->getText());
    }

    public function testTranslateChild()
    {
        $class = ChildTranslationComment::class;
        $parentClass = TranslatedComment::class;
        $field = 'text';
        $comment = $this->node->addNode('convert');
        $comment->setProperty($field, 'Lorem ipsum...');
        $comment->setProperty('phpcr:class', $class);
        $comment->setProperty('phpcr:classparents', [$parentClass]);
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en']));

        $this->session->save();

        $this->assertTrue($comment->hasNode('phpcr_locale:en'), 'translation was not created');
        $this->assertTrue($comment->getNode('phpcr_locale:en')->hasProperty($field), 'new property was not created');
        $this->assertFalse($comment->hasProperty($field), 'old property was not removed');

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->getText());

        $this->dm->clear();

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->getText());
    }

    public function testTranslateAttributeToChild()
    {
        $class = ChildTranslationComment::class;
        $parentClass = TranslatedComment::class;
        $field = 'text';
        $comment = $this->node->addNode('convert');
        $comment->setProperty(
            AttributeTranslationStrategyTest::propertyNameForLocale('en', $field),
            'Lorem ipsum...'
        );
        $comment->setProperty('phpcr:class', $class);
        $comment->setProperty('phpcr:classparents', $parentClass);
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en'], [], AttributeTranslationStrategy::NAME));

        $this->session->save();

        $this->assertTrue($comment->hasNode('phpcr_locale:en'), 'translation was not created');
        $this->assertTrue($comment->getNode('phpcr_locale:en')->hasProperty($field), 'new property was not created');
        $this->assertFalse(
            $comment->hasProperty(
                AttributeTranslationStrategyTest::propertyNameForLocale('en', $field)
            ),
            'old property was not removed'
        );

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->getText());

        $this->dm->clear();

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->getText());
    }

    public function testUntranslateAttribute()
    {
        $class = Comment::class;
        $field = 'title';
        $comment = $this->node->addNode('convert');
        $comment->setProperty(
            AttributeTranslationStrategyTest::propertyNameForLocale('en', $field),
            'Lorem ipsum...'
        );
        $comment->setProperty('phpcr:class', $class);
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en'], [$field], 'attribute'));

        $this->session->save();
        $this->assertTrue(
            $comment->hasProperty($field),
            'new property was not created'
        );

        $this->assertFalse(
            $comment->hasProperty(
                AttributeTranslationStrategyTest::propertyNameForLocale('en', $field)
            ),
            'old property was not removed'
        );

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->title);

        $this->dm->clear();

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->title);
    }

    /**
     * Just make one field of a document untranslated again.
     */
    public function testPartialUntranslateAttribute()
    {
        $article = new Article();
        $article->id = '/functional/convert';
        $article->topic = 'Some interesting subject';
        $article->setText('Lorem ipsum...');
        $this->dm->persist($article);
        $this->dm->flush();
        $this->dm->clear();

        $class = Article::class;
        $field = 'author';
        $comment = $this->node->getNode('convert');
        $comment->setProperty(
            AttributeTranslationStrategyTest::propertyNameForLocale('en', $field),
            'Move to untranslated'
        );
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en'], [$field]));
        $this->session->save();
        $this->dm->clear();

        $this->dm = $this->createDocumentManager();
        $this->dm->setLocaleChooserStrategy(new LocaleChooser($this->localePrefs, 'en'));

        $this->assertTrue(
            $comment->hasProperty($field),
            'new property was not created'
        );
        $this->assertFalse(
            $comment->hasProperty(
                AttributeTranslationStrategyTest::propertyNameForLocale('en', $field)
            ),
            'old property was not removed'
        );

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to untranslated', $article->author);
        $this->assertEquals('Lorem ipsum...', $article->getText());

        $this->dm->clear();

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to untranslated', $article->author);
        $this->assertEquals('Lorem ipsum...', $article->getText());
    }

    public function testUntranslateChild()
    {
        $class = Comment::class;
        $field = 'title';
        $comment = $this->node->addNode('convert');
        $comment->setProperty('phpcr:class', $class);
        $translation = $comment->addNode('phpcr_locale:en');
        $translation->setProperty($field, 'Lorem ipsum...');
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, [], [$field], 'child'));

        $this->session->save();

        $this->assertTrue(
            $comment->hasProperty($field),
            'new property was not created'
        );

        $this->assertFalse(
            $comment->hasNode('phpcr_locale:en'),
            'old property was not removed'
        );

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->title);

        $this->dm->clear();

        $commentDoc = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $commentDoc);
        $this->assertEquals('Lorem ipsum...', $commentDoc->title);
    }

    /**
     * Just make one field of a document untranslated again.
     */
    public function testPartialUntranslateChild()
    {
        $article = new ChildTranslationArticle();
        $article->id = '/functional/convert';
        $article->topic = 'Some interesting subject';
        $article->setText('Lorem ipsum...');
        $this->dm->persist($article);
        $this->dm->flush();
        $this->dm->clear();

        $class = ChildTranslationArticle::class;
        $field = 'author';
        $node = $this->node->getNode('convert');
        $node->getNode('phpcr_locale:en')->setProperty($field, 'Move to untranslated');
        $this->session->save();

        $this->assertFalse($this->converter->convert($class, ['en'], [$field]));
        $this->session->save();
        $this->dm->clear();

        $this->dm = $this->createDocumentManager();
        $this->dm->setLocaleChooserStrategy(new LocaleChooser($this->localePrefs, 'en'));

        $this->assertTrue(
            $node->hasProperty($field),
            'new property was not created'
        );
        $this->assertTrue(
            $node->hasNode('phpcr_locale:en'),
            'lost translation'
        );
        $this->assertFalse(
            $node->getNode('phpcr_locale:en')->hasProperty($field),
            'old property was not removed'
        );

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to untranslated', $article->author);
        $this->assertEquals('Lorem ipsum...', $article->getText());

        $this->dm->clear();

        $article = $this->dm->find(null, '/functional/convert');
        $this->assertInstanceof($class, $article);
        $this->assertEquals('Move to untranslated', $article->author);
        $this->assertEquals('Lorem ipsum...', $article->getText());
    }

    /**
     * Pass parent document and check message.
     */
    public function testTranslateStrict()
    {
        $this->converter = new TranslationConverter($this->dm);

        $class = ChildTranslationComment::class;
        $parentClass = TranslatedComment::class;
        $field = 'text';
        $comment = $this->node->addNode('convert');
        $comment->setProperty($field, 'Lorem ipsum...');
        $comment->setProperty('phpcr:class', $class);
        $comment->setProperty('phpcr:classparents', [$parentClass]);
        $this->session->save();

        $this->assertFalse($this->converter->convert($parentClass, ['en']));
        $notices = $this->converter->getLastNotices();
        $this->assertCount(1, $notices);
        $this->assertArrayHasKey('/functional/convert', $notices);

        $this->assertFalse($this->session->hasPendingChanges());
    }

    public function testUntranslateMissingPrevious()
    {
        $this->expectException(\Doctrine\ODM\PHPCR\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('To untranslate a document, you need to specify the previous translation strategy');
        $this->converter->convert(Comment::class, ['en']);
    }

    public function testUntranslateMissingFields()
    {
        $this->expectException(\Doctrine\ODM\PHPCR\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('need to specify the fields that where previously translated');
        $this->converter->convert(Comment::class, [], [], 'attribute');
    }

    /**
     * Converting to translated without specifying locales.
     */
    public function testMissingLocales()
    {
        $this->converter = new TranslationConverter($this->dm, 1);

        $this->expectException(\Doctrine\ODM\PHPCR\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('locales must be specified');
        $this->converter->convert(TranslatedComment::class, []);
    }
}
