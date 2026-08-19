<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\RfidCardRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\VehicleRepository;
use App\Services\RegistryService;

/**
 * RFID inventory pages.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class RfidController extends Controller
{
    /**
     * GET /rfid/tags
     */
    public function tags(Request $request): Response
    {
        return $this->render('pages/rfid/tags', [
            'title'    => 'Windshield tags',
            'summary'  => $this->service(RegistryService::class)->summary(),
            'statuses' => $this->service(RfidTagRepository::class)->statusCounts(),
            'vehicles' => $this->service(VehicleRepository::class)->selectList(),
            'tagTypes' => ['uhf_windshield', 'uhf_sticker', 'hf_card', 'lf_tag'],
            'can'      => [
                'create'     => $this->auth->can('rfid.create'),
                'update'     => $this->auth->can('rfid.update'),
                'assign'     => $this->auth->can('rfid.assign'),
                'deactivate' => $this->auth->can('rfid.deactivate'),
                'export'     => $this->auth->can('rfid.export'),
            ],
        ]);
    }

    /**
     * GET /rfid/cards
     */
    public function cards(Request $request): Response
    {
        return $this->render('pages/rfid/cards', [
            'title'     => 'Visitor cards',
            'statuses'  => $this->service(RfidCardRepository::class)->statusCounts(),
            'cardTypes' => ['hf_card', 'uhf_card', 'keyfob'],
            'can'       => [
                'create'     => $this->auth->can('rfid.create'),
                'deactivate' => $this->auth->can('rfid.deactivate'),
                'export'     => $this->auth->can('rfid.export'),
            ],
        ]);
    }
}
