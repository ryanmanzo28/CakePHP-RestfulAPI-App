<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;

class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('RequestHandler');
        $this->loadComponent('Authentication');
        $this->loadComponent('Csrf');
        $this->loadComponent('FormProtection');
        $this->viewBuilder()->setClassName('Json');
        
    }
}
