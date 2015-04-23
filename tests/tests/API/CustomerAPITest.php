<?php

use Illuminate\Http\RedirectResponse;
use Swapbot\Models\User;
use \PHPUnit_Framework_Assert as PHPUnit;

class CustomerAPITest extends TestCase {

    protected $use_database = true;

    public function testSuccessfulCustomerAPICall()
    {
        $swap_helper = app('SwapHelper');
        $swap = $swap_helper->newSampleSwap();

        $tester = app('APITestHelper');

        $created_customer_response = $tester->callAPIWithoutAuthentication('POST', '/api/v1/public/customers', [
            'email'  => 'customer001@tokenly.co',
            'swapId' => $swap['uuid'],
        ]);
        PHPUnit::assertEquals(200, $created_customer_response->getStatusCode(), "Unexpected response: ".$created_customer_response->getContent());
        $created_customer = json_decode($created_customer_response->getContent(), true);
        PHPUnit::assertNotEmpty($created_customer);

        // load the customer
        $customer_repository = app('Swapbot\Repositories\CustomerRepository');
        $actual_customer = $customer_repository->findByUUID($created_customer['id']);

        PHPUnit::assertNotEmpty($actual_customer);
        PHPUnit::assertEquals($swap['id'], $actual_customer['swap_id']);
    }



}