<?php

namespace App\Http\Controllers;


use App\Http\Services\GoogleWalletService;
use Google\Service\Walletobjects;
use Google_Service_Walletobjects_LoyaltyObject;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Google_Client;
use Google_Service_Walletobjects;

class GoogleController extends Controller
{
    protected $googleWalletService;

    public function __construct(GoogleWalletService $googleWalletService)
    {
        $this->googleWalletService = $googleWalletService;
    }

    public function generateWalletLink($objectId)
    {
        $walletService = new GoogleWalletService();
        $link = $walletService->generateSaveToWalletLink('3388000000022364941', $objectId);

        return response()->json(['saveToWalletLink' => $link]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'points' => 'required|integer|min:0',
        ]);

        $name = $request->input('name');
        $points = $request->input('points');

        try {
            $result = $this->googleWalletService->createLoyaltyObject($name, $points);
            return response()->json([
                'success' => true,
                'message' => 'Loyalty object created successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function updateLoyaltyObject(Request $request, $issuerId, $objectId)
    {
        $name = $request->input('name');
        $points = $request->input('points');

        $result = $this->googleWalletService->updateLoyaltyObject($issuerId, $objectId, $name, $points);

        return response()->json($result);
    }

    public function showLoyaltyClass($issuerId, $classId)
    {
        try {
            $loyaltyClass = $this->googleWalletService->getLoyaltyClass($issuerId, $classId);
            return response()->json($loyaltyClass);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function showLoyaltyObject($issuerId)
    {
        try {
            $loyaltyObject = $this->googleWalletService->getLoyaltyObject($issuerId);
            return response()->json($loyaltyObject);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
//    public function __construct()
//    {
//        $this->client = new Client();
////        $this->client->setAuthConfig(storage_path('app/google-wallet-key.json'));
//        $this->client->setClientId(env('GOOGLE_CLIENT_ID'));
//        $this->client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
//        $this->client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
//        // Directly define the scope as a string
//        $this->client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');
//
//        $this->service = new Walletobjects($this->client);
//    }
    public function redirectToGoogle()
    {
        $client = new Google_Client();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));
        $client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');

        $authUrl = $client->createAuthUrl();
        return redirect($authUrl);
    }

    public function handleGoogleCallback()
    {
        $client = new Google_Client();
        $client->setClientId(env('GOOGLE_CLIENT_ID'));
        $client->setClientSecret(env('GOOGLE_CLIENT_SECRET'));
        $client->setRedirectUri(env('GOOGLE_REDIRECT_URI'));

        if (request()->has('code')) {
            $client->authenticate(request('code'));
            session(['access_token' => $client->getAccessToken()]);
            return redirect()->route('wallet.objects');
        }

        return redirect()->route('google.redirect');
    }

    public function getWalletObjects()
    {
        $client = new Google_Client();
        $client->setAccessToken(session('access_token'));

        $service = new Google_Service_Walletobjects($client);

        $loyaltyObject = new Google_Service_Walletobjects_LoyaltyObject([
            'id' => '1',
            'accountId' => 'check',
            'accountName' => 'Ae',
            'programName' => 'Proge',
            // Add more fields as needed
        ]);

        $service->loyaltyobject->insert($loyaltyObject);

        return response()->json(['success' => true, 'object' => $loyaltyObject]);
    }

    public function getWalletObject($id)
    {
        $client = new Google_Client();
        $client->setAccessToken(session('access_token'));

        $service = new Google_Service_Walletobjects($client);

        $loyaltyObject = $service->loyaltyobject->get($id);

        return response()->json($loyaltyObject);
    }
}
