<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Settings\Branding;
use App\Domain\Settings\SettingsRepository;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Store identity in one place.
 *
 * Saving here repaints the storefront, new transactional emails, metadata
 * and new invoices. Orders already placed keep the branding snapshot taken
 * at the time, so historical documents are never rewritten.
 */
class BrandSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Brand & store details';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.brand-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('branding.manage'), 403);

        $settings = app(SettingsRepository::class);

        $this->form->fill([
            'brand_name' => $settings->string('brand.name', 'Pet Store'),
            'brand_tagline' => $settings->string('brand.tagline'),
            'accent_color' => $settings->string('brand.accent_color', '#3E7C78'),
            'accent_contrast' => $settings->string('brand.accent_contrast', '#FFFFFF'),
            'logo_light' => $settings->get('brand.logo_light'),
            'logo_dark' => $settings->get('brand.logo_dark'),
            'favicon' => $settings->get('brand.favicon'),
            'app_icon' => $settings->get('brand.app_icon'),
            'email_logo' => $settings->get('brand.email_logo'),
            'social' => $settings->array('brand.social'),
            'support_email' => $settings->string('contact.support_email'),
            'support_phone' => $settings->string('contact.support_phone'),
            'support_hours' => $settings->string('contact.support_hours'),
            'legal_name' => $settings->string('business.legal_name'),
            'address' => $settings->string('business.address'),
            'registration' => $settings->string('business.registration'),
            'seo_title' => $settings->string('seo.default_title'),
            'seo_description' => $settings->string('seo.default_description'),
            'seo_image' => $settings->get('seo.default_image'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()->tabs([
                    Tab::make('Identity')->schema([
                        TextInput::make('brand_name')->label('Store name')->required()->maxLength(80)
                            ->helperText('Shown in the header, emails and browser tab. The current name is a placeholder you can change at any time.'),
                        TextInput::make('brand_tagline')->label('Tagline')->maxLength(140),
                        ColorPicker::make('accent_color')->label('Accent colour')->required()
                            ->helperText('One restrained accent, applied across the storefront the moment you save.'),
                        ColorPicker::make('accent_contrast')->label('Text on accent')->required()
                            ->helperText('Must contrast strongly with the accent. Check it on a button before you ship it.'),
                    ])->columns(2),

                    Tab::make('Logos')->schema([
                        $this->image('logo_light', 'Logo for light backgrounds'),
                        $this->image('logo_dark', 'Logo for dark backgrounds'),
                        $this->image('email_logo', 'Email logo'),
                        $this->image('favicon', 'Favicon')->acceptedFileTypes(['image/png', 'image/x-icon', 'image/svg+xml']),
                        $this->image('app_icon', 'App icon (512×512)'),
                    ])->columns(2),

                    Tab::make('Contact')->schema([
                        TextInput::make('support_email')->email()->label('Support email'),
                        TextInput::make('support_phone')->tel()->label('Support phone'),
                        TextInput::make('support_hours')->label('Support hours'),
                        KeyValue::make('social')->label('Social links')
                            ->keyLabel('Network')->valueLabel('URL')->columnSpanFull(),
                    ])->columns(3),

                    Tab::make('Business details')->schema([
                        TextInput::make('legal_name')->label('Legal entity name')
                            ->helperText('Appears on invoices. Leave blank rather than guessing.'),
                        TextInput::make('registration')->label('Company or tax registration'),
                        Textarea::make('address')->rows(3)->label('Registered address')->columnSpanFull(),
                    ])->columns(2),

                    Tab::make('Search engine defaults')->schema([
                        TextInput::make('seo_title')->maxLength(70)->label('Default title'),
                        Textarea::make('seo_description')->rows(2)->maxLength(180)->label('Default description'),
                        $this->image('seo_image', 'Default sharing image (1200×630)'),
                    ])->columns(2),
                ]),
            ]);
    }

    private function image(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk('public')
            ->directory('brand')
            ->visibility('public')
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
            ->maxSize(2048)
            // Replacing an image deletes the previous file rather than
            // leaving orphans on disk.
            ->deleteUploadedFileUsing(fn (?string $file) => $file && Storage::disk('public')->delete($file));
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('branding.manage'), 403);

        $data = $this->form->getState();
        $settings = app(SettingsRepository::class);

        $settings->setMany([
            'brand.name' => $data['brand_name'],
            'brand.tagline' => $data['brand_tagline'],
            'brand.accent_color' => $data['accent_color'],
            'brand.accent_contrast' => $data['accent_contrast'],
            'brand.logo_light' => $data['logo_light'],
            'brand.logo_dark' => $data['logo_dark'],
            'brand.favicon' => $data['favicon'],
            'brand.app_icon' => $data['app_icon'],
            'brand.email_logo' => $data['email_logo'],
            'brand.social' => array_filter($data['social'] ?? []),
        ], 'brand');

        $settings->setMany([
            'contact.support_email' => $data['support_email'],
            'contact.support_phone' => $data['support_phone'],
            'contact.support_hours' => $data['support_hours'],
        ], 'contact');

        $settings->setMany([
            'business.legal_name' => $data['legal_name'],
            'business.address' => $data['address'],
            'business.registration' => $data['registration'],
        ], 'business');

        $settings->setMany([
            'seo.default_title' => $data['seo_title'],
            'seo.default_description' => $data['seo_description'],
            'seo.default_image' => $data['seo_image'],
        ], 'seo');

        // Drop the derived caches so the change is visible immediately.
        app(Branding::class)->flush();

        Notification::make()
            ->success()
            ->title('Brand updated')
            ->body('The storefront, new emails and new invoices now use these details. Orders already placed keep the branding they were issued with.')
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('branding.manage') ?? false;
    }
}
