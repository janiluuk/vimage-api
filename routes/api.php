<?php

use App\Http\Controllers\Api\V1\VideojobApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LaravelJsonApi\Laravel\Routing\ResourceRegistrar;
use App\Http\Controllers\Api\V2\Auth\LoginController;
use App\Http\Controllers\VideojobController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\GeneratorController;
use App\Http\Controllers\Api\V2\Auth\LogoutController;
use App\Http\Controllers\Api\V2\Auth\RegisterController;
use App\Http\Controllers\Api\V2\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V2\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V2\MeController;
use LaravelJsonApi\Laravel\Facades\JsonApiRoute;
use LaravelJsonApi\Laravel\Http\Controllers\JsonApiController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\SocialiteAuthController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\UserWalletController;
use App\Http\Controllers\Api\UserRatingController;
use App\Http\Controllers\Api\WalletTypeController;
use App\Http\Controllers\Api\SupportRequestController;
use App\Http\Controllers\Api\FinanceOperationsController;
use App\Http\Controllers\Api\SdInstanceController;
use LaravelJsonApi\Laravel\Routing\Relationships;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


JsonApiRoute::server('v1')
    ->prefix('v1')
    ->resources(function (ResourceRegistrar $server) {
        $server->resource('model-files', JsonApiController::class);
        $server->resource('generators', JsonApiController::class);

        $server->resource('video-jobs', VideojobApiController::class);
        $server->resource('categories', JsonApiController::class)->relationships(function ($relationships) {
            $relationships->hasMany('items');
        });
        $server->resource('users', JsonApiController::class)->relationships(function ($relationships) {
            $relationships->hasOne('role');
        });
        $server->resource('items', JsonApiController::class)->relationships(function ($relationships) {
            $relationships->hasMany('tags');
            $relationships->hasOne('category');
            $relationships->hasOne('user');
        });

        $server->resource('roles', JsonApiController::class)->relationships(function ($relationships) {
            $relationships->hasMany('permissions');
        });

        $server->resource('permissions', JsonApiController::class)->relationships(function ($relationships) { 
            $relationships->hasOne('role');
        })->only('index');



        $server->resource('tags', JsonApiController::class)->relationships(function ($relationships) {
            $relationships->hasMany('items');
        });
          
    });    

Route::prefix('v2')->middleware('json.api')->group(function () {
    Route::post('/login', LoginController::class)->name('login');
    Route::post('/logout', LogoutController::class)->middleware('auth:api');
    Route::post('/register', RegisterController::class);
    Route::post('/password-forgot', ForgotPasswordController::class);
    Route::post('/password-reset', ResetPasswordController::class)->name('password.reset');
});

JsonApiRoute::server('v2')->prefix('v2')->resources(function (ResourceRegistrar $server) {


    Route::get('me', [MeController::class, 'readProfile']);
    Route::patch('me', [MeController::class, 'updateProfile']);
});

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::post('/uploads/{resource}/{id}/{field}', UploadController::class);
});

Route::post('/upload', [VideojobController::class, 'upload'])->middleware('api');
Route::post('/generate', [VideojobController::class, 'generate'])->middleware('api');
Route::post('/finalize', [VideojobController::class, 'finalize'])->middleware('api');
Route::post('/cancelJob/{videoId}', [VideojobController::class, 'cancelJob'])->middleware('api');
Route::get('/queue', [VideojobController::class, 'getVideoJobs'])->middleware('auth:api');
Route::middleware('auth:api')->prefix('video-jobs')->group(function () {
    Route::get('/processing/status', [VideojobController::class, 'processingStatus']);
    Route::get('/processing/queue', [VideojobController::class, 'processingQueue']);
});

Route::get('/csrf-token', function () {
    return response()->json([
        'csrfToken' => csrf_token(),
    ]);
});
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('verified-email', [AuthController::class, 'emailVerification']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'sendLinkForgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});


Route::prefix('/administration')->group(function () {
    Route::middleware(['AuthorizationChecker', 'IsAdministratorChecker'])->group(function () {
        Route::get('/users', [UserController::class, 'getAllUsers']);
        Route::post('/support-requests', [SupportRequestController::class, 'getSupportRequestsByCriteria']);
        Route::patch('/admin-reset-user-password', [UserController::class, 'adminResetUserPassword']);
        Route::patch('/change-user-data', [UserController::class, 'changeUserData']);
        Route::get('/finance-operations/get-all', [FinanceOperationsController::class, 'getAllFinanceOperations']);
        Route::get('/orders', [OrderController::class, 'getAllOrders']);
        Route::patch('/orders/change-order-status', [OrderController::class, 'changeOrderStatus']);
        Route::patch('/change-password', [UserController::class, 'changePassword']);
        Route::get('/users/{userId}/data-stats', [UserController::class, 'getUserDataStats']);
        Route::delete('/users/purge-data', [UserController::class, 'purgeUserData']);
        
        // SD Instance management routes
        Route::get('/sd-instances', [SdInstanceController::class, 'index']);
        Route::post('/sd-instances', [SdInstanceController::class, 'store']);
        Route::get('/sd-instances/{id}', [SdInstanceController::class, 'show']);
        Route::put('/sd-instances/{id}', [SdInstanceController::class, 'update']);
        Route::patch('/sd-instances/{id}', [SdInstanceController::class, 'update']);
        Route::delete('/sd-instances/{id}', [SdInstanceController::class, 'destroy']);
        Route::patch('/sd-instances/{id}/toggle', [SdInstanceController::class, 'toggle']);
    });
});


Route::prefix('/categories')->group(
    function () {
        Route::get('/', [CategoryController::class, 'getCategories']);

        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('/by-user-id/{userId?}', [CategoryController::class, 'getCategoriesWithProductsForUser']);
        });

        Route::get('/{id}', [CategoryController::class, 'getCategoryById']);
    }
);

Route::prefix('/products')->group(
    function () {
        Route::get('', [ProductController::class, 'getProductsByCategoryId']);
        Route::patch('/{productId}', [ProductController::class, 'toggleActive']);

        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('/get-products-for-user', [ProductController::class, 'getProductsForUser']);
            Route::post('', [ProductController::class, 'create']);
            Route::put('/{productId}', [ProductController::class, 'update']);
            Route::delete('/{productId}', [ProductController::class, 'delete']);
        });

        Route::get('/{productId}', [ProductController::class, 'edit']);
    }
);

Route::prefix('/messages')->group(
    function () {
        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('/get-messages-by-chat-id/{chatId}', [MessageController::class, 'getMessagesByChatId']);
            Route::post('', [MessageController::class, 'addMessage']);
        });
    }
);

Route::prefix('/chats')->group(
    function () {
        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('/get-chats-by-current-user', [ChatController::class, 'getChatsByCurrentUser']);
            Route::get('/get-chat-by-user-id/{userId}', [ChatController::class, 'getChatByUserId']);
            Route::post('', [ChatController::class, 'create']);
            Route::get('/{chatId}', [ChatController::class, 'getChatById']);
        });
    }
);

Route::prefix('/finance-operations')->group(
    function () {
        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('', [FinanceOperationsController::class, 'getFinanceOperationsForCurrentUser']);
            Route::post('', [FinanceOperationsController::class, 'create']);
            Route::get('/{financeOperationsId}', [FinanceOperationsController::class, 'getFinanceOperationById']);
            Route::put('/{financeOperationsId}', [FinanceOperationsController::class, 'changeFinanceOperationStatusToCancel']);
            Route::patch('/change-finance-operation-status', [FinanceOperationsController::class, 'changeFinanceOperationStatus']);
        });
    }
);

Route::group(
    [
        'prefix' => '/wallet-types',
    ],
    function () {
        Route::get('', [WalletTypeController::class, 'getWalletTypes']);
    }
);

Route::group(
    [
        'prefix' => 'properties',
    ],
    function () {
        Route::get('', [PropertyController::class, 'getPropertyByCategoryId']);
    }
);

Route::prefix('/user-wallets')->group(
    function () {
        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('', [UserWalletController::class, 'getUserWalletsForCurrentUser']);
            Route::get('/by-wallet-type-id/{walletTypeId}', [UserWalletController::class, 'getUserWalletsByWalletTypeId']);
            Route::post('/', [UserWalletController::class, 'save']);
            Route::put('/', [UserWalletController::class, 'update']);
            Route::delete('/{userWalletId}', [UserWalletController::class, 'delete']);
        });
    }
);

Route::prefix('/orders')->group(
    function () {
        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('purchases', [OrderController::class, 'getPurchasesForCurrentUser']);
            Route::get('sales', [OrderController::class, 'getSalesForCurrentUser']);
            Route::post('', [OrderController::class, 'create']);
            Route::get('/{orderId}', [OrderController::class, 'getOrderById']);
            Route::patch('/confirm-order', [OrderController::class, 'confirmOrderById']);
        });
    }
);


Route::prefix('/user-ratings')->group(
    function () {
        Route::middleware('AuthorizationChecker')->group(function () {
            Route::get('/{userId?}', [UserRatingController::class, 'getUserRatingByUserId']);
        });
    }
);

Route::group(
    [
        'prefix' => 'support-request',
    ],
    function () {
        Route::post('', [SupportRequestController::class, 'sendSupportRequest']);
    }
);
Route::middleware('AuthorizationChecker')->group(function () {
    Route::post('/support-requests', [SupportRequestController::class, 'getSupportRequestsByCriteriaForUser']);
    Route::get('/support-request/{id}', [SupportRequestController::class, 'getSupportRequest']);
    Route::get('/support-request-messages/{id}', [SupportRequestController::class, 'getAllSupportRequestMessages']);
    Route::post('/send-support-request-message', [SupportRequestController::class, 'sendSupportRequestMessage']);
    Route::patch('/support-request/status-update', [SupportRequestController::class, 'updateSupportStatusRequest']);
});
Route::get('/status/{serviceName?}', [StatusController::class, 'status']);

Route::prefix('/')->group( function(){
    Route::get('/{providerName}/auth', [SocialiteAuthController::class, 'authUserFromSocialite']);
    Route::get('/{providerName}/callback', [SocialiteAuthController::class, 'addUserFromSocialite']);
});

Route::group(
    [
        'prefix' => '/questions',
    ],
    function () {
        Route::get('/', [QuestionController::class, 'getAll']);
        Route::get('/{questionSlug}', [QuestionController::class, 'getBySlug']);
    }
);

