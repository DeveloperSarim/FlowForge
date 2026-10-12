<?php

namespace App\Domain\License;

/** Verifies license tokens. The matching private key lives only on the license server. */
final class LicenseKey
{
    public const PUBLIC = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwRr8Jc+tI64z+Kg8Zrqu
QCekn1cw8RtjpbPIQtARtjR05IdSd4lJfLDhrQmAszaR0H9EqVxVHGD2vsNbGe8/
fqZx1MxnMwxbPr84kS2Iw0KgV8yoFEX0Q4i+Ph3SiJa1Esg7zdjicX2VQjUSU7xH
yiAtCBT38NX5bCXX3ETZThqx5q1cxjDOvkP6uFIVeOccLIIRZ+pOTzp63eMMRn42
MTvB5KinOO3xivDps1+IcGtBwYvIdmwRr4rEe7supwWo+fS7c8PoiwuFeTZEV2Z8
rpg2efOmaVrVJTLudosz0kfS/A8ENHlhsDrcUMFSdzWp6f6fOjGeqr3gsLmWEyk/
tQIDAQAB
-----END PUBLIC KEY-----
PEM;
}
