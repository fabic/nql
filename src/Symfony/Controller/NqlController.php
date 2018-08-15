<?php

namespace Fabic\Nql\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class NqlController extends AbstractController
{

    /**
     * @Route("/_nql", name="fabic_nql_index")
     */
    public function index(Request $request)
    {
        return $this->json(["hello!", "world"]);
    }
}