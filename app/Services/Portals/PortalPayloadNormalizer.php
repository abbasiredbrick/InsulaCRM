<?php

namespace App\Services\Portals;

class PortalPayloadNormalizer
{
    public function normalize(string $portal, array $data): array
    {
        if (is_array($data['lead'] ?? null)) {
            $data = array_merge($data, $data['lead']);
            unset($data['lead']);
        }

        $enquirer = is_array($data['enquirer'] ?? null) ? $data['enquirer'] : $data;
        $listing = is_array($data['listing'] ?? null) ? $data['listing'] : [];

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $customerName = $data['customerName']
            ?? $data['name']
            ?? null;

        if (is_string($customerName) && trim($customerName) === '') {
            $customerName = null;
        }

        $name = $enquirer['name']
            ?? $customerName
            ?? ($firstName !== '' || $lastName !== '' ? $firstName . ($lastName !== '' ? ' ' . $lastName : '') : '')
            ?? '';

        $email = $data['email']
            ?? $data['customerEmail']
            ?? (is_string($enquirer['email'] ?? null) ? $enquirer['email'] : null)
            ?? null;

        return [
            'id'           => $data['id']
                ?? $data['leadId']
                ?? $data['chat_id']
                ?? ($enquirer['id'] ?? null),
            'name'         => is_string($name) ? $name : '',
            'phone'        => $enquirer['phone_number']
                ?? $enquirer['phone']
                ?? $data['phone']
                ?? $data['mobile']
                ?? $data['phoneNumber']
                ?? null,
            'email'        => is_string($email) ? $email : null,
            'message'      => $data['message']
                ?? $data['inquiry']
                ?? $data['query']
                ?? ($enquirer['message'] ?? null),
            'reference'    => $listing['reference']
                ?? $data['listingReference']
                ?? $data['propertyReference']
                ?? $data['reference']
                ?? null,
            'url'          => $listing['url']
                ?? $data['listingUrl']
                ?? $data['listing_url']
                ?? $data['url']
                ?? null,
            'contact_link' => $enquirer['contact_link']
                ?? $data['contact_link']
                ?? $data['contactLink']
                ?? null,
            'received_at'  => $listing['received_at']
                ?? $data['received_at']
                ?? $data['receivedAt']
                ?? $data['created_at']
                ?? $data['createdAt']
                ?? null,
        ];
    }

    public function normalizeMultiple(string $portal, array $payloads): array
    {
        return array_map(fn ($payload) => $this->normalize($portal, $payload), $payloads);
    }
}