/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import "./styles/app.css";

import Alpine from "alpinejs";
import listeDeroulante, { formaterNumero } from "./composants/liste-deroulante";
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
                const cible = this.cibleErreur(premierInvalide);
                const bouton = cible === premierInvalide ? premierInvalide : cible.querySelector("button");
                (bouton || premierInvalide).focus();
                cible.scrollIntoView({ behavior: "smooth", block: "center" });
            }
        },

        // Une <select> masquée par une liste avec recherche (x-ref « natif ») :
        // l'erreur s'affiche sous la liste entière.
        cibleErreur(champ) {
            return champ.hidden && champ.parentElement ? champ.parentElement : champ;
        },

        afficherErreur(champ) {
            this.effacerErreur(champ);

            const cible = this.cibleErreur(champ);
            const message = document.createElement("p");
            message.dataset.erreurValidation = "true";
            message.className = "mt-1.5 text-sm text-red-700";
            message.textContent = this.messagePour(champ);
            cible.insertAdjacentElement("afterend", message);
            champ.classList.add("border-red-500");
        },

        effacerErreur(champ) {
            champ.classList.remove("border-red-500");

            const suivant = this.cibleErreur(champ).nextElementSibling;
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
            if (validite.rangeUnderflow) {
                return champ.dataset.messageMin || `La valeur minimale est ${champ.min}.`;
            }
            if (validite.rangeOverflow) {
                return `La valeur maximale est ${champ.max}.`;
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
 * Cascade du formulaire de dépôt : centre → métiers → certifications, et filtre
 * des certifications selon l'expérience.
 *
 * Les trois listes se déterminent en chaîne. Le centre ouvre des métiers, et le
 * couple (centre, métier) les certifications réellement préparées : proposer
 * les diplômes du métier sans tenir compte du centre laisserait choisir une
 * certification que l'établissement n'enseigne pas. Parmi elles, seules celles
 * du type accessible s'affichent : CQP de 5 à 6 ans d'expérience, CAP à partir
 * de 7 ans — ou CQP quand le centre ne prépare aucun CAP pour ce métier (seuils
 * portés par le champ des années, règle de ProfilProfessionnel::typeRetenu).
 *
 * Partagé par les quatre écrans qui servent ce formulaire (espace candidat,
 * inscription assistée, reprise, conseiller) plutôt que recopié dans chacun.
 * Les champs sont repérés par data-depot (centre, metier, certification,
 * annees) : la liste du centre est déjà une x-ref « natif » de la liste avec
 * recherche. Le serveur revalide toujours le triplet posté et le type du
 * diplôme : ce filtrage est un confort de saisie, jamais une garantie.
 */
window.depotCandidature = function () {
    const base = window.validationFormulaire();
    const initValidation = base.init;

    return Object.assign(base, {
        chargement: false,
        chargementCertifications: false,
        annees: "",
        // Offre du couple (centre, métier) : [{id, libelle, type}].
        certifications: [],
        certificationsChargees: false,
        formulaire: null,

        init() {
            initValidation.call(this);
            this.formulaire = this.$el;

            const annees = this.champ("annees");
            this.annees = annees ? annees.value : "";

            // Premier affichage : l'offre est déjà rendue par le serveur, avec le
            // type de chaque diplôme en data-type.
            const metier = this.champ("metier");
            const certification = this.champ("certification");
            if (certification && metier && metier.value) {
                this.certifications = Array.from(certification.options)
                    .filter((option) => option.value !== "")
                    .map((option) => ({ id: option.value, libelle: option.text, type: option.dataset.type || "" }));
                this.certificationsChargees = true;
            }
            this.afficherCertifications();
        },

        champ(nom) {
            return this.formulaire ? this.formulaire.querySelector(`[data-depot="${nom}"]`) : null;
        },

        seuil(nom) {
            const annees = this.champ("annees");

            return annees ? parseInt(annees.dataset[nom], 10) : NaN;
        },

        /** « CQP », « CAP » ou null (années non saisies ou insuffisantes). */
        typeAccessible() {
            const n = parseInt(this.annees, 10);

            if (Number.isNaN(n) || n < this.seuil("anneesCqp")) {
                return null;
            }

            return n >= this.seuil("anneesCap") ? "CAP" : "CQP";
        },

        /**
         * Type réellement proposé : celui de l'expérience, ou le CQP quand
         * l'offre chargée ne contient aucun CAP.
         */
        typeRetenu() {
            const type = this.typeAccessible();

            return type === "CAP" && this.repliCqp() ? "CQP" : type;
        },

        repliCqp() {
            return this.typeAccessible() === "CAP"
                && this.certificationsChargees
                && this.certifications.length > 0
                && !this.certifications.some((item) => item.type === "CAP");
        },

        anneesInsuffisantes() {
            const n = parseInt(this.annees, 10);

            return !Number.isNaN(n) && n < this.seuil("anneesCqp");
        },

        messageRepere() {
            const type = this.typeAccessible();

            if (this.anneesInsuffisantes()) {
                return `La VAE demande au moins ${this.seuil("anneesCqp")} ans d'expérience dans le métier.`;
            }
            if (type === null) {
                return "Saisissez vos années d'expérience : le diplôme accessible s'affiche ici.";
            }

            return `Avec ${parseInt(this.annees, 10)} ans d'expérience, vous visez un ${type}.`;
        },

        messageCertification() {
            const type = this.typeRetenu();

            if (this.repliCqp()) {
                return "Ce centre ne propose pas le CAP pour ce métier. Vous pouvez choisir un CQP.";
            }

            return type === null
                ? `Liste ouverte une fois vos années d'expérience saisies (${this.seuil("anneesCqp")} ans minimum).`
                : `Seuls les ${type} sont proposés pour votre expérience.`;
        },

        async chargerMetiers() {
            const centre = this.champ("centre");
            const metier = this.champ("metier");

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
            this.viderCertifications();
        },

        async chargerCertifications() {
            const metier = this.champ("metier");
            const certification = this.champ("certification");

            if (!certification) {
                return;
            }

            // En inscription assistée le centre est imposé : son champ n'existe
            // pas, et l'identifiant est alors porté par un attribut de données.
            const centre = this.champ("centre");
            const centreValeur = centre ? centre.value : certification.dataset.centre;

            if (!metier || !metier.value || !centreValeur) {
                this.viderCertifications();

                return;
            }

            this.chargementCertifications = true;
            this.certifications = [];
            certification.innerHTML = "";

            try {
                const reponse = await fetch(
                    `/api/candidature/centres/${encodeURIComponent(centreValeur)}/metiers/${encodeURIComponent(metier.value)}/certifications`,
                    { headers: { Accept: "application/json" } }
                );

                if (!reponse.ok) {
                    throw new Error("Chargement impossible");
                }

                this.certifications = (await reponse.json()).map((item) => ({
                    id: String(item.id),
                    libelle: item.libelle,
                    type: item.type || "",
                }));
                this.certificationsChargees = true;
            } catch (erreur) {
                this.certificationsChargees = false;
            } finally {
                this.chargementCertifications = false;
            }

            if (this.certificationsChargees) {
                this.afficherCertifications();
            } else {
                certification.innerHTML = "";
                certification.appendChild(new Option("Chargement impossible", ""));
            }
        },

        /**
         * Réécrit la liste des diplômes : l'offre du couple (centre, métier)
         * réduite au type accessible. Le choix courant est gardé s'il reste
         * proposé.
         */
        afficherCertifications() {
            const certification = this.champ("certification");

            if (!certification || this.chargementCertifications) {
                return;
            }

            const courant = certification.value;
            const type = this.typeRetenu();
            const ajouter = (libelle, valeur = "") => certification.appendChild(new Option(libelle, valeur));

            certification.innerHTML = "";

            if (!this.certificationsChargees) {
                ajouter("Sélectionnez d'abord un métier");
            } else if (this.certifications.length === 0) {
                ajouter("Aucun diplôme proposé pour ce métier dans ce centre");
            } else if (this.anneesInsuffisantes()) {
                ajouter(`Aucun diplôme accessible avant ${this.seuil("anneesCqp")} ans d'expérience`);
            } else if (type === null) {
                ajouter("Saisissez d'abord vos années d'expérience");
            } else {
                const proposees = this.certifications.filter((item) => item.type === type);

                if (proposees.length === 0) {
                    ajouter(`Aucun ${type} proposé pour ce métier dans ce centre`);
                } else {
                    ajouter("Sélectionnez le diplôme visé");
                    proposees.forEach((item) => {
                        const option = new Option(item.libelle, item.id);
                        option.dataset.type = item.type;
                        certification.appendChild(option);
                    });
                }
            }

            certification.value = Array.from(certification.options).some((option) => option.value === courant && courant !== "")
                ? courant
                : "";
        },

        viderCertifications() {
            this.certifications = [];
            this.certificationsChargees = false;
            this.afficherCertifications();
        },
    });
};

// Liste déroulante avec recherche (nationalité, indicatif téléphonique).
Alpine.data("listeDeroulante", listeDeroulante);
window.formaterNumero = formaterNumero;

window.Alpine = Alpine;
Alpine.start();

// start the Stimulus application
import "./bootstrap";
