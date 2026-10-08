<?php

namespace App\Payment\Gateway;

use App\Enum\MoyenPaiement;
use App\Enum\StatutPaiement;
use App\Payment\Dto\PaymentRequest;
use App\Payment\Dto\PaymentResponse;
use App\Payment\ConfigurationPasserelle;
use App\Payment\Exception\ConfigurationPasserelleException;
use App\Payment\PaymentGatewayInterface;
use App\Payment\SelecteurPasserelle;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Passerelle réelle : connecteur HTTP vers l'API du fournisseur de paiement
 * retenu (Trésor Pay, Orange Money, Wave…). Une seule classe pour le
 * paiement réel : changer de fournisseur, c'est adapter ce fichier à sa
 * documentation et changer les valeurs PAIEMENT_* de .env.local.
 *

 */
#[AutoconfigureTag(SelecteurPasserelle::TAG)]
class PaymentGateway implements PaymentGatewayInterface
{
    private const NOM = 'api';
    private const DEVISE = 'XOF';
    private const DELAI_REPONSE_SECONDES = 20;

    /** Tolérance d'horodatage d'une notification : au-delà, rejouée = refusée. */
    public const TOLERANCE_NOTIFICATION_SECONDES = 300;
    /** En-tête de signature des notifications (en minuscules), à aligner sur le fournisseur. */
    public const ENTETE_SIGNATURE = 'x-signature';

    /** Statuts du fournisseur → statuts métier. Tout statut inconnu reste « en attente ». */
    private const STATUTS = [
        'SUCCESS' => StatutPaiement::REUSSI,
        'SUCCEEDED' => StatutPaiement::REUSSI,
        'PAID' => StatutPaiement::REUSSI,
        'PENDING' => StatutPaiement::EN_ATTENTE,
        'INITIATED' => StatutPaiement::EN_ATTENTE,
        'PROCESSING' => StatutPaiement::EN_ATTENTE,
        'FAILED' => StatutPaiement::ECHOUE,
        'REJECTED' => StatutPaiement::ECHOUE,
        'EXPIRED' => StatutPaiement::ECHOUE,
        'CANCELLED' => StatutPaiement::ANNULE,
        'CANCELED' => StatutPaiement::ANNULE,
        'REFUNDED' => StatutPaiement::REMBOURSE,
    ];

    /** Moyens proposés au candidat → code attendu par le fournisseur. */
    private const MOYENS = [
        'mobile_money' => 'MOBILE_MONEY',
        'carte' => 'CARD',
        'guichet' => 'CASH_COUNTER',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ConfigurationPasserelle $configuration,
    ) {
    }

    public static function cle(): string
    {
        return self::NOM;
    }

    public function initier(PaymentRequest $requete): PaymentResponse
    {
        $donnees = $this->appeler('POST', '/payments', [
            'merchant_id' => $this->configuration->identifiantMarchand,
            'reference' => $requete->reference,
            // Le franc CFA n'a pas de centimes : montant entier, jamais un flottant.
            'amount' => (int) round((float) $requete->montant),
            'currency' => self::DEVISE,
            'description' => $requete->description,
            'payment_method' => self::MOYENS[$requete->moyenPaiement->value] ?? null,
            'customer' => [
                'name' => $requete->candidatNom,
                'phone' => $requete->candidatContact,
            ],
            'return_url' => $requete->urlRetour,
            'notify_url' => $this->configuration->urlNotification !== '' ? $this->configuration->urlNotification : null,
            'metadata' => $requete->metadonnees,
        ]);

        return $this->reponse($donnees, $requete->reference);
    }

    public function verifier(string $referenceTransaction): PaymentResponse
    {
        return $this->reponse(
            $this->appeler('GET', '/payments/' . rawurlencode($referenceTransaction)),
            $referenceTransaction
        );
    }

    public function annuler(string $referenceTransaction): PaymentResponse
    {
        return $this->reponse(
            $this->appeler('POST', '/payments/' . rawurlencode($referenceTransaction) . '/cancel'),
            $referenceTransaction
        );
    }

    public function rembourser(string $referenceTransaction, string $montant): PaymentResponse
    {
        return $this->reponse(
            $this->appeler('POST', '/payments/' . rawurlencode($referenceTransaction) . '/refund', [
                'amount' => (int) round((float) $montant),
                'currency' => self::DEVISE,
            ]),
            $referenceTransaction
        );
    }

    public function supporte(MoyenPaiement $moyen): bool
    {
        // Jamais les moyens de simulation : ils n'existent pas chez le fournisseur.
        return isset(self::MOYENS[$moyen->value]);
    }

    public function nom(): string
    {
        return self::NOM;
    }

    public function estSimulation(): bool
    {
        return false;
    }

    /**
     * Authentifie une notification serveur à serveur et en extrait la référence.
     *
     * En-tête attendu : « t=<horodatage unix>,v1=<HMAC-SHA256 hexadécimal> »,
     * calculé sur « <horodatage>.<corps brut> » avec le secret de notification.
     * Refus si la signature est absente, fausse (comparaison à temps constant)
     * ou trop ancienne (protection contre le rejeu). Le statut éventuellement
     * présent dans le corps est ignoré : l'appelant revérifie par verifier().
     */
    public function lireNotification(string $corps, array $entetes, ?int $maintenant = null): ?string
    {
        $signature = $entetes[self::ENTETE_SIGNATURE] ?? null;
        $secret = $this->configuration->secretNotification;

        if ($secret === '' || $signature === null || $signature === '') {
            return null;
        }

        $parties = [];
        foreach (explode(',', $signature) as $morceau) {
            [$cle, $valeur] = array_pad(explode('=', trim($morceau), 2), 2, '');
            $parties[$cle] = $valeur;
        }
        $horodatage = ctype_digit($parties['t'] ?? '') ? (int) $parties['t'] : null;
        $empreinte = $parties['v1'] ?? '';

        if ($horodatage === null || $empreinte === '') {
            return null;
        }
        if (abs(($maintenant ?? time()) - $horodatage) > self::TOLERANCE_NOTIFICATION_SECONDES) {
            return null;
        }

        $attendue = hash_hmac('sha256', $horodatage . '.' . $corps, $secret);
        if (!hash_equals($attendue, $empreinte)) {
            return null;
        }

        $donnees = json_decode($corps, true);
        $reference = is_array($donnees) ? ($donnees['reference'] ?? null) : null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /** Hôte de la page de paiement : la seule destination de redirection admise. */
    public function hoteAutorise(): ?string
    {
        return $this->configuration->hote();
    }

    /**
     * @param array<string, mixed>|null $corps
     *
     * @return array<string, mixed>
     */
    private function appeler(string $methode, string $chemin, ?array $corps = null): array
    {
        $this->verifierConfiguration();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->cleApi,
                'X-Merchant-Id' => $this->configuration->identifiantMarchand,
                'Accept' => 'application/json',
            ],
            'timeout' => self::DELAI_REPONSE_SECONDES,
        ];
        if ($corps !== null) {
            $options['json'] = array_filter($corps, static fn ($v) => $v !== null);
        }

        $reponse = $this->httpClient->request($methode, rtrim($this->configuration->urlApi, '/') . $chemin, $options);
        $code = $reponse->getStatusCode();
        // false : ne pas lever d'exception sur 4xx/5xx, la réponse est analysée.
        $donnees = json_decode($reponse->getContent(false), true);

        if ($code >= 500) {
            // Indisponibilité : remontée au service, qui la traduit pour le candidat.
            throw new \RuntimeException(sprintf('Passerelle de paiement indisponible (HTTP %d).', $code));
        }

        $this->logger->info('Appel de l\'API de paiement', ['methode' => $methode, 'chemin' => $chemin, 'code' => $code]);

        $donnees = is_array($donnees) ? $donnees : [];
        $donnees['_http'] = $code;

        return $donnees;
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function reponse(array $donnees, string $reference): PaymentResponse
    {
        $code = (int) ($donnees['_http'] ?? 200);
        $statutBrut = strtoupper((string) ($donnees['status'] ?? ''));
        $statut = $code >= 400 ? StatutPaiement::ECHOUE : (self::STATUTS[$statutBrut] ?? StatutPaiement::EN_ATTENTE);
        $erreur = $donnees['error'] ?? null;

        return new PaymentResponse(
            succes: $statut !== StatutPaiement::ECHOUE,
            statut: $statut,
            referenceTransaction: (string) ($donnees['reference'] ?? $reference),
            identifiantExterne: isset($donnees['transaction_id']) ? (string) $donnees['transaction_id'] : null,
            urlRedirection: isset($donnees['payment_url']) ? (string) $donnees['payment_url'] : null,
            codeErreur: is_array($erreur) ? (string) ($erreur['code'] ?? 'ERREUR') : ($code >= 400 ? 'HTTP_' . $code : null),
            messageErreur: is_array($erreur) ? (string) ($erreur['message'] ?? '') : null,
            // Réponse conservée pour l'audit (TransactionPaiement), sans la clé d'API.
            reponseBrute: $donnees,
            horodatage: new \DateTimeImmutable(),
        );
    }

    private function verifierConfiguration(): void
    {
        $manquants = $this->configuration->manquants();

        if ($manquants !== []) {
            throw ConfigurationPasserelleException::parametresManquants(self::NOM, $manquants);
        }
        if (!$this->configuration->estChiffree()) {
            throw ConfigurationPasserelleException::connexionNonChiffree();
        }
    }
}
