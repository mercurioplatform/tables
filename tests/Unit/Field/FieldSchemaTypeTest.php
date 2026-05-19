<?php

namespace Mercurio\Tables\Tests\Unit\Field;

use Mercurio\Tables\Field\AvatarField;
use Mercurio\Tables\Field\BadgesField;
use Mercurio\Tables\Field\BelongsToField;
use Mercurio\Tables\Field\BelongsToManyField;
use Mercurio\Tables\Field\BooleanField;
use Mercurio\Tables\Field\ConditionalColorField;
use Mercurio\Tables\Field\DateField;
use Mercurio\Tables\Field\DiscountedMoneyField;
use Mercurio\Tables\Field\ImageField;
use Mercurio\Tables\Field\JsonField;
use Mercurio\Tables\Field\MoneyField;
use Mercurio\Tables\Field\NumberField;
use Mercurio\Tables\Field\ProgressBarField;
use Mercurio\Tables\Field\RatingField;
use Mercurio\Tables\Field\RelationCountField;
use Mercurio\Tables\Field\StatusField;
use Mercurio\Tables\Field\TagsField;
use Mercurio\Tables\Field\TextField;
use Mercurio\Tables\Field\TwoLineField;
use Mercurio\Tables\Tests\TestCase;

final class FieldSchemaTypeTest extends TestCase
{
    public function test_text_field_defaults(): void
    {
        $f = TextField::make('x');
        $this->assertSame('text', $f->schemaType());
        $this->assertSame([], $f->formatHints());
    }

    public function test_status_field(): void
    {
        $f = StatusField::make('status')
            ->kinds(['a' => 'success'])
            ->labels(['a' => 'Алёша']);

        $this->assertSame('status', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame(['a' => 'success'], $hints['kinds']);
        $this->assertSame(['a' => 'Алёша'], $hints['labels']);
    }

    public function test_money_field_hints(): void
    {
        $f = MoneyField::make('total')->divisor(100)->currency('€', 'before')->decimals(2);

        $this->assertSame('money', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('€', $hints['currency']);
        $this->assertSame(100, $hints['divisor']);
        $this->assertSame('before', $hints['position']);
        $this->assertSame(2, $hints['decimals']);
        $this->assertArrayHasKey('decimal_separator', $hints);
        $this->assertArrayHasKey('thousands_separator', $hints);
    }

    public function test_number_field_hints(): void
    {
        $f = NumberField::make('x')->decimals(3)->editMin(0)->editMax(100)->editStep('0.01');

        $this->assertSame('number', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame(3, $hints['decimals']);
        $this->assertSame(0, $hints['edit_min']);
        $this->assertSame(100, $hints['edit_max']);
        $this->assertSame('0.01', $hints['edit_step']);
    }

    public function test_date_field_absolute(): void
    {
        $f = DateField::make('x')->format('d.m.Y');

        $this->assertSame('date', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('absolute', $hints['mode']);
        $this->assertSame('d.m.Y', $hints['format']);
    }

    public function test_date_field_relative_default(): void
    {
        $f = DateField::make('x');

        $hints = $f->formatHints();
        $this->assertSame('relative', $hints['mode']);
    }

    public function test_boolean_field(): void
    {
        $f = BooleanField::make('x')->labels('Да', 'Нет');

        $this->assertSame('boolean', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('Да', $hints['true_label']);
        $this->assertSame('Нет', $hints['false_label']);
    }

    public function test_belongs_to_field(): void
    {
        $f = BelongsToField::make('customer_id')->relation('customer')->displayKey('name');

        $this->assertSame('belongs_to', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('customer', $hints['relation']);
        $this->assertSame('name', $hints['display_key']);
        $this->assertArrayHasKey('foreign_key', $hints);
    }

    public function test_belongs_to_many_field(): void
    {
        $f = BelongsToManyField::make('tags')->displayKey('title')->previewLimit(5);

        $this->assertSame('belongs_to_many', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('tags', $hints['relation']);
        $this->assertSame('title', $hints['display_key']);
        $this->assertSame(5, $hints['preview_limit']);
    }

    public function test_json_field(): void
    {
        $f = JsonField::make('payload')->pretty()->maxLength(200);

        $this->assertSame('json', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertTrue($hints['pretty']);
        $this->assertSame(200, $hints['max_length']);
        $this->assertArrayHasKey('expandable', $hints);
    }

    public function test_progress_bar_field(): void
    {
        $f = ProgressBarField::make('done')->capacity(100)->lowThreshold(10)->highThreshold(90)->barWidth('120px');

        $this->assertSame('progress_bar', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame(100, $hints['capacity']);
        $this->assertSame(10, $hints['low_threshold']);
        $this->assertSame(90, $hints['high_threshold']);
        $this->assertSame('120px', $hints['bar_width']);
    }

    public function test_progress_bar_field_capacity_closure(): void
    {
        $f = ProgressBarField::make('done')->capacity(fn () => 100);
        $this->assertSame('closure', $f->formatHints()['capacity']);
    }

    public function test_rating_field(): void
    {
        $f = RatingField::make('rate')->max(10)->precision(2)->style('bar');

        $this->assertSame('rating', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame(10, $hints['max']);
        $this->assertSame(2, $hints['precision']);
        $this->assertSame('bar', $hints['style']);
    }

    public function test_tags_field(): void
    {
        $f = TagsField::make('labels')->relation('tags')->displayKey('name')->limit(5)->variant('info');

        $this->assertSame('tags', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('tags', $hints['relation']);
        $this->assertSame('name', $hints['display_key']);
        $this->assertSame(5, $hints['limit']);
        $this->assertSame('info', $hints['variant']);
    }

    public function test_badges_field(): void
    {
        $f = BadgesField::make('flags');

        $this->assertSame('badges', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertArrayHasKey('subtle', $hints);
        $this->assertArrayHasKey('gap', $hints);
        $this->assertArrayHasKey('using_closure', $hints);
        $this->assertFalse($hints['using_closure']);
    }

    public function test_image_field(): void
    {
        $f = ImageField::make('avatar')->size(64)->shape('circle')->placeholderIcon('bi-person');

        $this->assertSame('image', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame(64, $hints['size']);
        $this->assertSame('circle', $hints['shape']);
        $this->assertSame('bi-person', $hints['placeholder_icon']);
    }

    public function test_avatar_field(): void
    {
        $f = AvatarField::make('user')->size(32);

        $this->assertSame('avatar', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame(32, $hints['size']);
    }

    public function test_relation_count_field(): void
    {
        $f = RelationCountField::make('orders')->withLabel()->iconBefore('bi-bag');

        $this->assertSame('relation_count', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertTrue($hints['with_label']);
        $this->assertSame('bi-bag', $hints['icon_before']);
        $this->assertArrayHasKey('decimals', $hints);
    }

    public function test_conditional_color_field(): void
    {
        $f = ConditionalColorField::make('delta')->colorUsing(fn ($v) => 'danger');

        $this->assertSame('conditional_color', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertArrayHasKey('decimals', $hints);
        $this->assertTrue($hints['color_using_closure']);
    }

    public function test_two_line_field(): void
    {
        $f = TwoLineField::make('summary')->subMono(true);

        $this->assertSame('two_line', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertTrue($hints['sub_mono']);
        $this->assertArrayHasKey('empty_text', $hints);
    }

    public function test_discounted_money_field(): void
    {
        $f = DiscountedMoneyField::make('price')->divisor(100)->currency('₽')->showPercentage(true);

        $this->assertSame('discounted_money', $f->schemaType());
        $hints = $f->formatHints();
        $this->assertSame('₽', $hints['currency']);
        $this->assertSame(100, $hints['divisor']);
        $this->assertTrue($hints['show_percentage']);
        $this->assertFalse($hints['compare_using_closure']);
    }
}
