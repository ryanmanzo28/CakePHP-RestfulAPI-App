<?php
namespace app\controller\api;
use firebase\JWT\JWT;


class AuthController extends Controller
{
    public function login()
    {
        $user = $this->Users
        ->find()
        ->where(['email' => $this->request->getData('email')])
            ->first();

        if (!$user) {
            return $this->response->withStatus(401);
        }

        $payload = [
            'sub' => $user->id,
            'exp' => time() + 3600, // Token expires in 1 hour

        ];
        $token = JWT::encode(
            $payload,
            'secret-key',
            HS256_KEY,

        );
        return $this->response->withType('application/json')
            ->withStringBody(json_encode([
                'token' => $token,
            ]));
        
    }

}


