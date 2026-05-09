<?php
namespace App\Base\Foundation\Contracts;

interface CompanyScoped
{
    /**
     * Get the company ID the entity belongs to.
     */
    public function getCompanyId(): ?int;
}
