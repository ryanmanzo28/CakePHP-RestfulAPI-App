<?php
namespace App\Controller;

use Cake\Controller\Controller;

// Minimal AppController to satisfy controllers extending it.
if (class_exists('\\Cake\\Controller\\Controller')) {
    class AppController extends Controller
    {
        public function initialize(): void
        {
            parent::initialize();
        }
    }
} else {
    // Fallback stub when Cake is not installed; provides compatible methods used by our controllers.
    class AppController
    {
        protected $request;
        protected $response;

        public function initialize(): void {}

        public function loadComponent($name) {}

        public function request()
        {
            return $this->request;
        }
    }
}
