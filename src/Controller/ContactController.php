<?php

namespace App\Controller;

use App\Service\PageConfiguration;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    private const MAX_ATTACHMENT_SIZE = 5 * 1024 * 1024;

    public function __construct(
        private readonly PageConfiguration $pageConfiguration,
        private readonly Connection $connection,
        #[Autowire('%dispatch_upload_directory%')]
        private readonly string $dispatchUploadDirectory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $contact = $this->buildContactData();
        $values = array_fill_keys([
            'vessel_name', 'imo_number', 'vessel_type', 'company', 'contact_person',
            'phone_number', 'eta_date', 'location', 'service_type', 'description',
        ], '');
        $values['country_code'] = '+63';
        $values['urgent'] = false;
        $errors = [];
        $errorStatus = null;
        $status = Response::HTTP_OK;
        // Preserve the original login contract: the login handler sets this server-side session value.
        $sessionIdentity = $request->getSession()->get('user_id');
        $clientId = is_int($sessionIdentity) || is_string($sessionIdentity) ? filter_var($sessionIdentity, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) : false;

        if ($request->isMethod('POST')) {
            $submitted = $request->request->all();
            foreach ($values as $field => $default) {
                if ($field === 'urgent') {
                    continue;
                }
                $value = $submitted[$field] ?? '';
                if (!is_string($value)) {
                    $errors[$field] = 'Enter a valid value.';
                    $values[$field] = '';
                    continue;
                }
                $values[$field] = trim($value);
            }
            $values['urgent'] = ($submitted['urgent'] ?? null) === '1';
            $token = $submitted['_token'] ?? null;

            if ($clientId === false) {
                $errorStatus = 'Clearance denied: You must authenticate before submitting.';
                $status = Response::HTTP_FORBIDDEN;
            } elseif (!is_string($token) || !$this->isCsrfTokenValid('dispatch_request', $token)) {
                $errorStatus = 'Your form session expired. Please try submitting again.';
                $status = Response::HTTP_FORBIDDEN;
            } else {
                $errors = array_replace($errors, $this->validateRequest($values, $contact));
                $attachment = $request->files->all()['attachment'] ?? null;
                $attachmentExtension = null;
                if ($attachment !== null) {
                    if (!$attachment instanceof UploadedFile || !$attachment->isValid()) {
                        $errors['attachment'] = 'The file could not be uploaded. Please select it again.';
                    } elseif ($attachment->getSize() > self::MAX_ATTACHMENT_SIZE) {
                        $errors['attachment'] = 'The attachment must be 5 MB or smaller.';
                    } else {
                        $attachmentExtension = match ($attachment->getMimeType()) {
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'application/pdf' => 'pdf',
                            default => null,
                        };
                        if ($attachmentExtension === null) {
                            $errors['attachment'] = 'Only JPG, PNG, and PDF files are permitted.';
                        }
                    }
                }

                if ($errors !== []) {
                    $errorStatus = 'Please correct the highlighted fields.';
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                } else {
                    $storedFile = null;
                    $attachmentPath = null;
                    try {
                        if ($attachment instanceof UploadedFile && $attachmentExtension !== null) {
                            if (!is_dir($this->dispatchUploadDirectory)
                                && !@mkdir($this->dispatchUploadDirectory, 0750, true)
                                && !is_dir($this->dispatchUploadDirectory)) {
                                throw new FileException('Unable to create the dispatch upload directory.');
                            }
                            $filename = 'dispatch_'.bin2hex(random_bytes(16)).'.'.$attachmentExtension;
                            $storedFile = $attachment->move($this->dispatchUploadDirectory, $filename)->getPathname();
                            $attachmentPath = 'var/uploads/dispatch/'.$filename;
                        }

                        $this->connection->insert('dispatch_requests', [
                            'client_id' => $clientId,
                            'vessel_name' => $values['vessel_name'],
                            'imo_number' => $values['imo_number'],
                            'vessel_type' => $values['vessel_type'],
                            'company' => $values['company'],
                            'contact_person' => $values['contact_person'],
                            'contact_phone' => $values['country_code'].' '.$values['phone_number'],
                            'eta_date' => $values['eta_date'],
                            'service_type' => $values['service_type'],
                            'is_urgent' => $values['urgent'] ? 1 : 0,
                            'location_id' => (int) $values['location'],
                            'description' => $values['description'],
                            'attachment_path' => $attachmentPath,
                            'status' => 'pending',
                            'is_active' => 1,
                        ]);
                    } catch (\Throwable $exception) {
                        if ($storedFile !== null && is_file($storedFile)) {
                            @unlink($storedFile);
                        }
                        $this->logger->error('Unable to save a dispatch request.', ['exception' => $exception]);
                        $errorStatus = 'Your request could not be saved. Please try again or contact Central Dispatch directly.';
                        $status = Response::HTTP_SERVICE_UNAVAILABLE;
                    }

                    if ($errorStatus === null) {
                        $this->addFlash('dispatch_success', sprintf(
                            'Dispatch request for %s successfully transmitted to Central Command.',
                            $values['vessel_name'],
                        ));

                        return $this->redirectToRoute('app_contact', [], Response::HTTP_SEE_OTHER);
                    }
                }
            }
        }

        $routes = $this->container->get('router')->getRouteCollection();

        return $this->render('pages/contact.html.twig', [
            'configuration' => $this->pageConfiguration->build('contact'),
            'contact' => $contact,
            'values' => $values,
            'errors' => $errors,
            'error_status' => $errorStatus,
            'can_submit' => $clientId !== false,
            'login_url' => $routes->get('app_login') !== null ? $this->generateUrl('app_login') : null,
            'register_url' => $routes->get('app_register') !== null ? $this->generateUrl('app_register') : null,
        ], new Response(status: $status));
    }

    private function validateRequest(array $values, array $contact): array
    {
        $errors = [];
        foreach ([
            'vessel_name' => 'vessel name', 'imo_number' => 'IMO number',
            'vessel_type' => 'vessel type', 'contact_person' => 'contact person',
            'country_code' => 'country code', 'phone_number' => 'phone number',
            'eta_date' => 'ETA / service date', 'location' => 'service location',
            'service_type' => 'service type', 'description' => 'request details',
        ] as $field => $label) {
            if ($values[$field] === '') {
                $errors[$field] = 'Please provide the '.$label.'.';
            }
        }
        foreach (['vessel_name' => 255, 'company' => 255, 'contact_person' => 255, 'description' => 10000] as $field => $limit) {
            if (mb_strlen($values[$field]) > $limit) {
                $errors[$field] = sprintf('Use %d characters or fewer.', $limit);
            }
        }
        if ($values['imo_number'] !== '' && !preg_match('/^\d{7}$/D', $values['imo_number'])) {
            $errors['imo_number'] = 'Enter a seven-digit IMO number.';
        }
        if ($values['phone_number'] !== '' && (!preg_match('/^[0-9() .-]{5,30}$/D', $values['phone_number'])
            || strlen(preg_replace('/\D/', '', $values['phone_number'])) < 5)) {
            $errors['phone_number'] = 'Enter a valid phone number, using 5 to 30 characters.';
        }
        foreach (['vessel_type' => 'vessel_types', 'country_code' => 'country_codes', 'service_type' => 'service_types'] as $field => $choices) {
            if ($values[$field] !== '' && !array_key_exists($values[$field], $contact[$choices])) {
                $errors[$field] = 'Select one of the available options.';
            }
        }
        $locationIds = array_merge(...array_values(array_map('array_keys', $contact['locations'])));
        if ($values['location'] !== '' && !in_array($values['location'], array_map('strval', $locationIds), true)) {
            $errors['location'] = 'Select one of the available service locations.';
        }
        if ($values['eta_date'] !== '') {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $values['eta_date'])
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $values['eta_date'])
                : false;
            if ($date === false || $date->format('Y-m-d') !== $values['eta_date']) {
                $errors['eta_date'] = 'Enter a valid service date.';
            }
        }

        return $errors;
    }

    private function buildContactData(): array
    {
        return [
            'email' => 'dispatch@apexmarine.com.ph',
            'hubs' => [
                ['title' => 'Philippines (HQ)', 'badge' => null, 'entries' => [
                    ['location' => 'Negros Occidental, Hinoba-an', 'phone' => '+63 917 000 1234', 'dial' => '+639170001234'],
                ]],
                ['title' => 'APAC Hub', 'badge' => 'Rapid Deployment', 'entries' => [
                    ['location' => 'Singapore', 'phone' => '+65 6000 8888', 'dial' => '+6560008888'],
                    ['location' => 'Shanghai, China', 'phone' => '+86 21 5555 0000', 'dial' => '+862155550000'],
                ]],
                ['title' => 'EMEA Hub', 'badge' => null, 'entries' => [
                    ['location' => 'Rotterdam, Netherlands', 'phone' => '+31 10 123 4567', 'dial' => '+31101234567'],
                    ['location' => 'Dubai, UAE', 'phone' => '+971 4 123 4567', 'dial' => '+97141234567'],
                    ['location' => 'Cape Town, South Africa', 'phone' => '+27 21 123 4567', 'dial' => '+27211234567'],
                ]],
                ['title' => 'Americas Hub', 'badge' => null, 'entries' => [
                    ['location' => 'Houston, USA', 'phone' => '+1 (713) 555-0100', 'dial' => '+17135550100'],
                    ['location' => 'Panama City, Panama', 'phone' => '+507 200-0000', 'dial' => '+5072000000'],
                ]],
            ],
            'channels' => [
                ['label' => 'WhatsApp / Viber', 'handle' => '+63 917 000 1234', 'icon' => 'chat'],
                ['label' => 'LinkedIn', 'handle' => 'Apex Marine Engineering', 'icon' => 'linkedin'],
                ['label' => 'Facebook', 'handle' => '@ApexMarinePH', 'icon' => 'facebook'],
                ['label' => 'Instagram', 'handle' => '@apexmarine_ph', 'icon' => 'instagram'],
            ],
            'vessel_types' => [
                'Bulk Carrier' => 'Bulk Carrier', 'Container Ship' => 'Container Ship',
                'Oil/Chemical Tanker' => 'Oil/Chemical Tanker', 'Offshore/Tug' => 'Offshore / Tug',
                'Yacht/Passenger' => 'Yacht / Passenger',
            ],
            'country_codes' => [
                '+63' => '+63 (PH)', '+1' => '+1 (US/CA)', '+65' => '+65 (SG)', '+86' => '+86 (CN)',
                '+31' => '+31 (NL)', '+971' => '+971 (UAE)', '+27' => '+27 (ZA)', '+507' => '+507 (PA)',
                '+44' => '+44 (UK)', 'Sat' => 'SAT',
            ],
            'locations' => [
                'APAC Hub (Rapid Deployment)' => [1 => 'Singapore', 2 => 'Shanghai, China'],
                'EMEA Hub' => [3 => 'Rotterdam, Netherlands', 4 => 'Dubai, UAE', 5 => 'Cape Town, South Africa'],
                'Americas Hub' => [6 => 'Houston, USA', 7 => 'Panama City, Panama'],
            ],
            'service_types' => [
                'Propulsion & Machinery' => 'Propulsion & Machinery',
                'Electrical & Automation' => 'Electrical & Automation',
                'Hydraulics & Deck Gear' => 'Hydraulics & Deck Gear',
                'Hull & Steel Fabrication' => 'Hull & Steel Fabrication',
                'Preventative Maintenance' => 'Preventative Maintenance',
                'General Consultation' => 'Other / General Consultation',
            ],
        ];
    }
}
