<?php

use App\Http\Controllers\Api\CardStackPreviewController;
use App\Http\Controllers\Collection\CardStackController;
use App\Http\Controllers\Collection\CollectionController;
use App\Http\Controllers\Collection\ContainerController;
use App\Http\Controllers\Collection\ExportController;
use App\Http\Controllers\Collection\ImportController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\Decks\DeckCardController;
use App\Http\Controllers\Decks\DeckCardSearchController;
use App\Http\Controllers\Decks\DeckCategoryController;
use App\Http\Controllers\Decks\DecksController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\DeckSortController;
use App\Http\Controllers\User\DeckViewController;
use App\Http\Controllers\WelcomeController;
use App\Http\Middleware\HandleControllerPrecognitiveRequest;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

/******************************************************************************
 * Guest pages
 *****************************************************************************/
Route::get('/', [WelcomeController::class, 'show'])
    ->name('welcome');
Route::post('/lang/{locale}', [LocaleController::class, 'update'])
    ->name('locale');
Route::get('/about', [GuestController::class, 'about'])
    ->name('about');
Route::get('/privacy', [GuestController::class, 'privacy'])
    ->name('privacy');
Route::get('/imprint', [GuestController::class, 'imprint'])
    ->name('imprint');

/******************************************************************************
 * Authed pages
 *****************************************************************************/
Route::middleware(array_filter(['auth', Features::enabled(Features::emailVerification()) ? 'verified' : null]))->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show'])
        ->name('dashboard');
    Route::post('/currency/{currency}', [CurrencyController::class, 'update'])
        ->name('currency');
    Route::post('/deck-view-default', [DeckViewController::class, 'update'])
        ->name('deck_view_default.update');
    Route::post('/deck-sort-default', [DeckSortController::class, 'update'])
        ->name('deck_sort_default.update');

    // collection
    Route::get('/collection', [CollectionController::class, 'list'])
        ->name('collection');
    Route::delete('/collection', [CollectionController::class, 'destroy'])
        ->name('collection.destroy');
    Route::get('collection/export', [ExportController::class, 'collection'])
        ->name('collection.export');
    Route::get('collection/import', [ImportController::class, 'show'])
        ->name('collection.import');
    Route::post('collection/import/upload', [ImportController::class, 'upload'])
        ->name('collection.import.upload');
    Route::post('collection/import', [ImportController::class, 'store'])
        ->name('collection.import.store');

    // Containers
    Route::get('containers', [ContainerController::class, 'list'])
        ->name('containers');
    Route::get('containers/new', [ContainerController::class, 'create'])
        ->name('container.new');
    Route::post('containers/new', [ContainerController::class, 'store'])
        ->middleware([HandleControllerPrecognitiveRequest::class])
        ->name('container.store');
    Route::patch('containers/sort', [ContainerController::class, 'reorder'])
        ->name('container.reorder');
    Route::get('containers/qr', [ContainerController::class, 'generateQr'])
        ->name('containers.qr');
    Route::get('containers/{container}/export', [ExportController::class, 'container'])
        ->name('container.export');
    Route::get('containers/{container}/import', [ImportController::class, 'show'])
        ->name('container.import');
    Route::get('containers/{container}/edit', [ContainerController::class, 'edit'])
        ->name('container.edit');
    Route::patch('containers/{container}', [ContainerController::class, 'update'])
        ->middleware([HandleControllerPrecognitiveRequest::class])
        ->name('container.update');
    Route::delete('containers/{container}', [ContainerController::class, 'destroy'])
        ->name('container.destroy');
    Route::delete('containers/{container}/prune', [ContainerController::class, 'prune'])
        ->name('container.prune');
    Route::get('containers/{container}/qr', [ContainerController::class, 'generateQr'])
        ->name('container.qr');
    Route::post('containers/{container}/qr', [ContainerController::class, 'qrSvg'])
        ->name('container.qr.svg');

    // cardstacks
    Route::get('collection/add', [CardStackController::class, 'add'])
        ->name('cards.add');
    Route::get('containers/{container}/add', [CardStackController::class, 'add'])
        ->name('container.cards.add');
    Route::post('collection/add', [CardStackController::class, 'store'])
        ->middleware([HandleControllerPrecognitiveRequest::class])
        ->name('cards.store');
    Route::patch('collection/cardstack/move', [CardStackController::class, 'moveSelected'])
        ->name('cardstack.moveSelected');
    Route::patch('containers/{container}/move-all', [CardStackController::class, 'massMove'])
        ->name('cardstack.massMove');
    Route::delete('collection/cardstack/delete-selected', [CardStackController::class, 'destroySelected'])
        ->name('cardstack.destroySelected');
    Route::get('collection/cardstack/{cardStack}/edit', [CardStackController::class, 'edit'])
        ->name('cardstack.edit');
    Route::patch('collection/cardstack/{cardStack}', [CardStackController::class, 'update'])
        ->middleware([HandleControllerPrecognitiveRequest::class])
        ->name('cardstack.update');
    Route::delete('collection/cardstack/{cardStack}', [CardStackController::class, 'destroy'])
        ->name('cardstack.destroy');
    Route::get('collection/cardstack/{cardStack}/preview', [CardStackPreviewController::class, 'show'])
        ->name('cardstack.preview');

    // decks
    Route::get('/decks', [DecksController::class, 'list'])
        ->name('decks');
    Route::get('/decks/add', [DecksController::class, 'create'])
        ->name('decks.create');
    Route::post('/decks/add', [DecksController::class, 'store'])
        ->middleware([HandleControllerPrecognitiveRequest::class])
        ->name('decks.store');
    Route::get('/decks/{deck}', [DecksController::class, 'show'])
        ->name('decks.show');
    Route::get('/api/decks/{deck}/card-search/oracle', [DeckCardSearchController::class, 'oracle'])
        ->name('api.decks.card-search.oracle');
    Route::get('/api/decks/{deck}/card-search/printings', [DeckCardSearchController::class, 'printings'])
        ->name('api.decks.card-search.printings');
    Route::post('/api/decks/{deck}/cards', [DeckCardController::class, 'store'])
        ->name('api.decks.cards.store');
    Route::post('/decks/{deck}/categories', [DeckCategoryController::class, 'store'])
        ->middleware([HandleControllerPrecognitiveRequest::class])
        ->name('decks.categories.store');
    Route::get('/api/decks/{deck}/cards/{deckCard}/printings', [DeckCardController::class, 'printings'])
        ->name('api.decks.cards.printings');
    Route::patch('/api/decks/{deck}/cards/{deckCard}/category', [DeckCardController::class, 'updateCategory'])
        ->name('api.decks.cards.update-category');
    Route::patch('/api/decks/{deck}/cards/{deckCard}/printing', [DeckCardController::class, 'updatePrinting'])
        ->name('api.decks.cards.update-printing');
    Route::patch('/api/decks/{deck}/cards/{deckCard}/split', [DeckCardController::class, 'split'])
        ->name('api.decks.cards.split');
    Route::patch('/api/decks/{deck}/cards/{deckCard}/quantity', [DeckCardController::class, 'updateQuantity'])
        ->name('api.decks.cards.update-quantity');
    Route::delete('/api/decks/{deck}/cards/{deckCard}', [DeckCardController::class, 'destroy'])
        ->name('api.decks.cards.destroy');
    Route::delete('/api/decks/{deck}/categories/{deckCategory}', [DeckCategoryController::class, 'destroy'])
        ->name('api.decks.categories.destroy');

});

/******************************************************************************
 * Public container page (visibility check handled in controller)
 * Must be registered after the auth group so that specific routes like
 * containers/new, containers/qr, containers/sort are matched first.
 *****************************************************************************/
Route::get('containers/{container}', [ContainerController::class, 'show'])
    ->name('container.show');

/******************************************************************************
 * Dev pages (no auth, not linked from anywhere)
 *****************************************************************************/
Route::get('/icons', fn () => Inertia::render('Dev/Icons'))
    ->name('dev.icons');
