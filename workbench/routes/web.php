<?php

declare(strict_types=1);

// No routes: the package registers its own `/` on each central domain (`home`)
// and on the tenant group (`tenant.home`), and the tenant one replaces
// anything declared here, since Laravel keys a route by method, domain and
// URI. Visit the harness on a central domain — http://localhost:8000, not
// http://127.0.0.1:8000, which identifies as neither and 500s with
// TenantCouldNotBeIdentifiedOnDomainException.
