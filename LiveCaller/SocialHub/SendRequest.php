<?php

namespace App\Actions\SocialHub;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Lorisleiva\Actions\Action;

class SendRequest extends Action
{
    /**
     * Determine if the user is authorized to make this action.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the action.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'method' => 'required|in:get,post',
            'data' => 'nullable|array',
            'token' => 'required|string',
            'url' => 'required|url',
        ];
    }

    /**
     * Execute the action and return a result.
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle(): JsonResponse
    {
        $client = new Client(['verify' => !app()->isLocal()]);

        try {
            $request = $client->request($this->get('method'), $this->get('url'), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->get('token'),
                ],
                'json' => $this->get('data'),
                'http_errors' => false,
            ]);

            $response = $request->getBody()->getContents();
        } catch (ClientException $exception) {
            $response = $exception->getResponse()->getBody()->getContents();
        }

        $code = ($request ?? false) ? $request->getStatusCode() : 400;

        return response()->json(json_decode($response, true) ?? [], $code >= 500 ? 400 : $code);
    }
}
