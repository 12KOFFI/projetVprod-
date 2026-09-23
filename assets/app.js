/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import "./styles/app.css";

import Alpine from "alpinejs";
import { Chart, DoughnutController, ArcElement, Tooltip } from "chart.js";

Chart.register(DoughnutController, ArcElement, Tooltip);

/**
 * Palette imposée des graphiques.
 *
 * Les couleurs par défaut de Chart.js sont un arc-en-ciel étranger à la charte :
 * elles réintroduiraient des teintes absentes de `tailwind.config.js`. Toute
 * série reçoit donc explicitement ces valeurs, dérivées du bleu marine DAIP
 * (`daipBlue`) pour les séries sans signification propre, et des couleurs
 * sémantiques du projet quand la teinte porte un sens.
 */
const PALETTE_GRAPHIQUE = [
    "#0B669D", // indigo/blue-600 — daipBlue
    "#5FA6C9", // blue-400
    "#B9DDED", // blue-200
    "#075786", // blue-700
    "#8DC4DE", // blue-300
    "#03476F", // blue-800
];

/** Teintes sémantiques, à nommer explicitement dans `data-couleurs`. */
const COULEURS_SEMANTIQUES = {
    succes: "#22C55E", // green-500
    attente: "#F59E0B", // amber-500
    refus: "#EF4444", // red-500
    neutre: "#94A3B8", // slate-400
};

/**
 * Graphique circulaire d'un écran de pilotage.
 *
 * Les données ne sont pas écrites en JavaScript dans le gabarit : le canvas
 * porte un attribut `data-valeurs` (JSON `{libellé: effectif}`) et, au besoin,
 * un `data-couleurs` listant des clés sémantiques. Un seul point d'entrée
 * évite un `<script>` par graphique.
 */
function monterGraphiques() {
    document.querySelectorAll("canvas[data-valeurs]").forEach((canvas) => {
        let valeurs;

        try {
            valeurs = JSON.parse(canvas.dataset.valeurs);
        } catch (e) {
            return;
        }

        const libelles = Object.keys(valeurs);

        // Un graphique sans donnée afficherait un disque vide et trompeur :
        // le gabarit prévoit un état vide à la place, on n'instancie rien.
        if (libelles.length === 0) {
            return;
        }

        let couleurs;
        const cles = canvas.dataset.couleurs;

        if (cles) {
            couleurs = cles
                .split(",")
                .map((cle, index) => COULEURS_SEMANTIQUES[cle.trim()] ?? PALETTE_GRAPHIQUE[index % PALETTE_GRAPHIQUE.length]);
        } else {
            couleurs = libelles.map((_, index) => PALETTE_GRAPHIQUE[index % PALETTE_GRAPHIQUE.length]);
        }

        new Chart(canvas, {
            type: "doughnut",
            data: {
                labels: libelles,
                datasets: [
                    {
                        data: Object.values(valeurs),
                        backgroundColor: couleurs,
                        borderColor: "#FFFFFF",
                        borderWidth: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: "62%",
                plugins: {
                    // La légende est rendue en Twig sous le graphique, avec des
                    // effectifs chiffrés : celle de Chart.js ferait doublon.
                    legend: { display: false },
                },
            },
        });
    });
}

document.addEventListener("DOMContentLoaded", monterGraphiques);

/**
 * Contrôle client d'une pièce justificative (partials/_ui.html.twig, macro
 * document) : dès qu'un fichier est choisi, son format et sa taille sont
 * vérifiés dans le navigateur pour afficher une confirmation immédiate, sans
 * attendre l'aller-retour serveur.
 */
window.pieceJustificative = function () {
    const formatsValides = ["image/jpeg", "image/png", "application/pdf"];
    const tailleMaxOctets = 2 * 1024 * 1024;

    return {
        fichier: null,
        erreur: null,

        verifier(evenement) {
            const brut = evenement.target.files[0];

            if (!brut) {
                this.fichier = null;
                this.erreur = null;

                return;
            }

            if (!formatsValides.includes(brut.type)) {
                this.fichier = null;
                this.erreur = "Format non accepté — envoyez un JPG, un PNG ou un PDF.";

                return;
            }

            if (brut.size > tailleMaxOctets) {
                this.fichier = null;
                this.erreur = "Fichier trop volumineux — 2 Mo maximum.";

                return;
            }

            this.erreur = null;
            this.fichier = {
                nom: brut.name,
                taille: (brut.size / (1024 * 1024)).toFixed(2),
            };
        },
    };
};

/**
 * Validation d'un formulaire avant sa soumission : le `novalidate` posé sur
 * chaque `<form>` désactive seulement les bulles natives du navigateur, pas
 * l'API de validation elle-même. On s'en sert ici pour afficher, en français
 * et dans le style du reste de l'interface, les mêmes erreurs (champ
 * obligatoire, format invalide…) avant que la requête ne parte, plutôt que de
 * les découvrir après coup dans la réponse du serveur.
 */
window.validationFormulaire = function () {
    return {
        init() {
            this.$el.addEventListener("input", (evenement) => this.effacerErreur(evenement.target));
            this.$el.addEventListener("change", (evenement) => this.effacerErreur(evenement.target));
        },

        verifierAvantEnvoi(evenement) {
            const formulaire = evenement.target;

            if (formulaire.checkValidity()) {
                return;
            }

            evenement.preventDefault();

            let premierInvalide = null;

            formulaire.querySelectorAll(":invalid").forEach((champ) => {
                this.afficherErreur(champ);
                premierInvalide = premierInvalide ?? champ;
            });

            if (premierInvalide) {
                premierInvalide.focus();
                premierInvalide.scrollIntoView({ behavior: "smooth", block: "center" });
            }
        },

        afficherErreur(champ) {
            this.effacerErreur(champ);

            const message = document.createElement("p");
            message.dataset.erreurValidation = "true";
            message.className = "mt-1.5 text-sm text-red-700";
            message.textContent = this.messagePour(champ);
            champ.insertAdjacentElement("afterend", message);
            champ.classList.add("border-red-500");
        },

        effacerErreur(champ) {
            champ.classList.remove("border-red-500");

            const suivant = champ.nextElementSibling;
            if (suivant && suivant.dataset && suivant.dataset.erreurValidation) {
                suivant.remove();
            }
        },

        messagePour(champ) {
            const validite = champ.validity;

            if (validite.valueMissing) {
                return "Ce champ est obligatoire.";
            }
            if (validite.tooShort) {
                return `Ce champ doit contenir au moins ${champ.minLength} caractères.`;
            }
            if (validite.tooLong) {
                return `Ce champ ne peut pas dépasser ${champ.maxLength} caractères.`;
            }
            if (validite.rangeUnderflow || validite.rangeOverflow) {
                return "La valeur saisie est hors des limites autorisées.";
            }
            if (validite.patternMismatch) {
                return champ.title || "Le format saisi n'est pas valide.";
            }
            if (validite.typeMismatch) {
                return "Le format saisi n'est pas valide.";
            }

            return "La valeur saisie n'est pas valide.";
        },
    };
};

/**
 * Cascade du formulaire de dépôt : centre → métiers → certifications.
 *
 * Les trois listes se déterminent en chaîne. Le centre ouvre des métiers, et le
 * couple (centre, métier) les certifications réellement préparées : proposer
 * les diplômes du métier sans tenir compte du centre laisserait choisir une
 * certification que l'établissement n'enseigne pas.
 *
 * Partagé par les quatre écrans qui servent ce formulaire (espace candidat,
 * inscription assistée, reprise, conseiller) plutôt que recopié dans chacun.
 * Le serveur revalide toujours le triplet posté : cette cascade est un confort
 * de saisie, jamais une garantie.
 */
window.depotCandidature = function () {
    return Object.assign(window.validationFormulaire(), {
        chargement: false,
        chargementCertifications: false,

        async chargerMetiers() {
            const centre = this.$refs.centre;
            const metier = this.$refs.metier;

            if (!centre || !metier || !centre.value) {
                return;
            }

            this.chargement = true;
            metier.innerHTML = "";

            try {
                const reponse = await fetch(
                    `/api/candidature/centres/${encodeURIComponent(centre.value)}/metiers`,
                    { headers: { Accept: "application/json" } }
                );

                if (!reponse.ok) {
                    throw new Error("Chargement impossible");
                }

                const metiers = await reponse.json();
                metier.appendChild(new Option("Sélectionnez un métier", ""));

                metiers.forEach((item) => {
                    const option = new Option(item.libelle, item.id);
                    option.dataset.filiere = item.filiere ?? "";
                    metier.appendChild(option);
                });
            } catch (erreur) {
                metier.appendChild(new Option("Aucun métier disponible", ""));
            } finally {
                this.chargement = false;
            }

            // Changer de centre invalide la certification déjà choisie : elle
            // appartenait à l'offre de l'ancien couple.
            this.viderCertifications("Sélectionnez d'abord un métier");
        },

        async chargerCertifications() {
            const metier = this.$refs.metier;
            const certification = this.$refs.certification;

            if (!certification) {
                return;
            }

            // En inscription assistée le centre est imposé : son champ n'existe
            // pas, et l'identifiant est alors porté par un attribut de données.
            const centreValeur = this.$refs.centre
                ? this.$refs.centre.value
                : certification.dataset.centre;

            if (!metier || !metier.value || !centreValeur) {
                this.viderCertifications("Sélectionnez d'abord un métier");

                return;
            }

            this.chargementCertifications = true;
            certification.innerHTML = "";

            try {
                const reponse = await fetch(
                    `/api/candidature/centres/${encodeURIComponent(centreValeur)}/metiers/${encodeURIComponent(metier.value)}/certifications`,
                    { headers: { Accept: "application/json" } }
                );

                if (!reponse.ok) {
                    throw new Error("Chargement impossible");
                }

                const certifications = await reponse.json();

                if (certifications.length === 0) {
                    certification.appendChild(
                        new Option("Aucun diplôme proposé pour ce métier dans ce centre", "")
                    );

                    return;
                }

                certification.appendChild(new Option("Sélectionnez le diplôme visé", ""));
                certifications.forEach((item) => {
                    certification.appendChild(new Option(item.libelle, item.id));
                });
            } catch (erreur) {
                certification.appendChild(new Option("Chargement impossible", ""));
            } finally {
                this.chargementCertifications = false;
            }
        },

        viderCertifications(message) {
            const certification = this.$refs.certification;

            if (!certification) {
                return;
            }

            certification.innerHTML = "";
            certification.appendChild(new Option(message, ""));
        },
    });
};

window.Alpine = Alpine;
Alpine.start();

// start the Stimulus application
import "./bootstrap";
