<?php

use App\Base\Foundation\Contracts\CompanyScoped;

class FakeCompanyScopedAuthenticatable extends FakeAuthenticatable implements CompanyScoped
{
    public function __construct(int $id, private readonly ?int $companyId)
    {
        parent::__construct($id, ['company_id' => $companyId]);
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }
}
