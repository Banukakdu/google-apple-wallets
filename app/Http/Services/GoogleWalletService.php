<?php

namespace App\Http\Services;
use Google\Client;
use Firebase\JWT\JWT;
use Google\Service\Walletobjects;
use Google_Service_Walletobjects;
use Google_Service_Walletobjects_LoyaltyPoints;
use Google_Service_Walletobjects_LoyaltyPointsBalance;

class GoogleWalletService
{
    protected $client;
    protected $walletObjectsService;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuthConfig(storage_path('app/wallet-432407-4cf8ddedbbed.json'));
        $this->client->setScopes(['https://www.googleapis.com/auth/wallet_object.issuer']);

        $this->walletObjectsService = new Walletobjects($this->client);
//        $this->service = new Google_Service_Walletobjects($this->client);
    }

    public function updateLoyaltyObject($issuerId, $objectId, $name, $points)
    {
        try {
            // Construct the Loyalty Object ID
            $loyaltyObjectId = $issuerId . '.' . $objectId;

            // Retrieve the existing Loyalty Object
            $existingLoyaltyObject = $this->walletObjectsService->loyaltyobject->get($loyaltyObjectId);

            // Create a new LoyaltyPoints object
            $loyaltyPoints = new Google_Service_Walletobjects_LoyaltyPoints();
            $loyaltyPointsBalance = new Google_Service_Walletobjects_LoyaltyPointsBalance();
            $loyaltyPointsBalance->setInt($points); // Use setInt() for integer balance
            $loyaltyPoints->setBalance($loyaltyPointsBalance);

            // Update fields as necessary
            $existingLoyaltyObject->setAccountName($name);
            $existingLoyaltyObject->setLoyaltyPoints($loyaltyPoints);

            // Update the Loyalty Object in Google Wallet
            return $this->walletObjectsService->loyaltyobject->update($loyaltyObjectId, $existingLoyaltyObject);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function generateSaveToWalletLink($issuerId, $loyaltyObjectId)
    {
        $credentials = json_decode(file_get_contents(env('GOOGLE_WALLET_KEY_PATH')), true);
        $claims = [
            "iss" => $credentials['client_email'],
            "aud" => "google",
            "origins" => ["https://go.plezed.com"],
            "typ" => "savetowallet",
            "payload" => [
                "loyaltyObjects" => [
                    [
                        "id" => $issuerId . '.' . $loyaltyObjectId
                    ]
                ]
            ]
        ];

        $privateKey = $credentials['private_key'];

        $jwt = JWT::encode($claims, $privateKey, 'RS256');

        return "https://pay.google.com/gp/v/save/$jwt";
    }



    public function createLoyaltyObject($name, $points)
    {
        $issuerId = '3388000000022364941'; // Replace this with your actual issuer ID
        $loyaltyObjectId = $issuerId . '.' . uniqid(); // Creates a valid ID
        // Create a new loyalty object with the required parameters

        $loyaltyObject = new Walletobjects\LoyaltyObject([
            'classId' => $issuerId .'.loyalty123', // Replace with your class ID
            'id' => $loyaltyObjectId, // Generate a unique ID
            'state' => 'active',
            'accountId' => uniqid(),
            'accountName' => $name,
            'loyaltyPoints' => [
                'balance' => [
                    'int' => $points
                ],
                'label' => 'Points'
            ],
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => '1234567890'
            ],
            // Add other required fields as necessary
        ]);

        // Insert the loyalty object into Google Wallet
        return $this->walletObjectsService->loyaltyobject->insert($loyaltyObject);
    }

    public function getLoyaltyClass($issuerId, $classId)
    {
//        return $this->service->loyaltyClass->get($issuerId . '.' . $classId);
        return $this->walletObjectsService->loyaltyobject->get($issuerId . '.' . $classId);
    }

    public function getLoyaltyObject($id)
    {
        return $this->walletObjectsService->loyaltyobject->get($id);
    }


}
