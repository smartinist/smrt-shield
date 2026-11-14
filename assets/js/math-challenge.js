( function () {
    'use strict';

    const refreshed = new WeakSet();
    const popupTimers = new WeakMap();

    function schedulePopupScan( popup, delay ) {
        window.clearTimeout( popupTimers.get( popup ) );
        popupTimers.set( popup, window.setTimeout( function () {
            scan( popup, true );
        }, delay ) );
    }

    function setResetSafeValue( field, value ) {
        field.value = value;
        field.setAttribute( 'value', value );
    }

    function setResetSafeName( field, name ) {
        field.name = name;
        field.setAttribute( 'name', name );
    }

    function refreshForm( container, force ) {
        if ( refreshed.has( container ) && ! force ) {
            return;
        }

        const endpoint = container.dataset.challengeUrl;
        const formId = container.dataset.formId;
        const form = container.closest( 'form' );
        const timestamp = form ? form.querySelector( '[data-smrt-shield-timestamp]' ) : null;
        const timestampHash = form ? form.querySelector( '[data-smrt-shield-timestamp-hash]' ) : null;
        const timestampProof = form ? form.querySelector( '[data-smrt-shield-timestamp-proof]' ) : null;
        const math = form ? form.querySelector( '[data-smrt-shield-math]' ) : null;
        const question = math ? math.querySelector( '[data-smrt-shield-question]' ) : null;
        const token = math ? math.querySelector( '[data-smrt-shield-token]' ) : null;
        const hash = math ? math.querySelector( '[data-smrt-shield-hash]' ) : null;
        const mathProof = math ? math.querySelector( '[data-smrt-shield-math-proof]' ) : null;
        const answer = math ? math.querySelector( '[name="smrt_shield_math_answer"]' ) : null;

        if (
            ! endpoint ||
            ! formId ||
            ! form ||
            ! timestamp ||
            ! timestampHash ||
            ! timestampProof ||
            ( math && ( ! question || ! token || ! hash || ! mathProof || ! answer ) )
        ) {
            return;
        }

        refreshed.add( container );

        const separator = endpoint.includes( '?' ) ? '&' : '?';
        const url = endpoint + separator + 'form_id=' + encodeURIComponent( formId ) + '&_=' + Date.now();

        fetch( url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json'
            }
        } )
            .then( function ( response ) {
                if ( ! response.ok ) {
                    throw new Error( 'Challenge request failed.' );
                }

                return response.json();
            } )
            .then( function ( challenge ) {
                // An Atomic submission can be underway when a refresh arrives.
                if ( ( container.hasAttribute( 'data-smrt-shield-atomic' ) && form.dataset.atomicFormSubmitting )
                    || ( answer && answer.value !== '' && force !== 'retry' ) ) {
                    return;
                }
                if ( ! challenge.timestamp || ! challenge.timestampHash || ! challenge.timestampProof ) {
                    return;
                }

                setResetSafeValue( timestamp, challenge.timestamp );
                setResetSafeValue( timestampHash, challenge.timestampHash );
                setResetSafeName( timestampProof, 'smrt_shield_timestamp_proof[' + challenge.timestampProof + ']' );

                if ( ! math ) {
                    return;
                }

                if ( ! challenge.question || ! challenge.token || ! challenge.hash || ! challenge.mathProof ) {
                    return;
                }

                question.textContent = challenge.question;
                setResetSafeValue( token, challenge.token );
                setResetSafeValue( hash, challenge.hash );
                setResetSafeName( mathProof, 'smrt_shield_math_proof[' + challenge.mathProof + ']' );
                answer.value = '';
            } )
            .catch( function () {
                // Keep the valid server-rendered, reset-resistant fallback.
            } );
    }

    // Atomic Forms 4.4 serialize only their own inputs. Send proof as separate
    // request parameters so it never becomes part of Elementor's submitted data.
    document.addEventListener( 'elementor/atomic-form/before-submit', function ( event ) {
        const form = event.target;
        const container = form.querySelector( '[data-smrt-shield-atomic]' );
        if ( ! container || typeof event.detail?.addFields !== 'function' ) {
            return;
        }

        const proof = {};
        form.querySelectorAll( '[data-smrt-shield-form] input, [data-smrt-shield-math] input' ).forEach( function ( input ) {
            if ( input.name ) {
                proof[ input.name ] = input.value;
            }
        } );
        event.detail.addFields( proof );
    } );

    // Each arithmetic proof is good for one attempt. Allow retry without
    // clearing the other fields, including after a failed challenge.
    new MutationObserver( function ( mutations ) {
        mutations.forEach( function ( mutation ) {
            const form = mutation.target;
            if ( ! form.matches( 'form[data-e-type="e-form"]' ) || form.hasAttribute( 'data-atomic-form-submitting' ) ) {
                return;
            }
            const container = form.querySelector( '[data-smrt-shield-atomic]' );
            if ( container ) {
                refreshForm( container, 'retry' );
            }
        } );
    } ).observe( document.documentElement, { subtree: true, attributes: true, attributeFilter: [ 'data-atomic-form-submitting' ] } );

    function scan( root, force ) {
        if ( root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE ) {
            return;
        }

        if ( root.matches && root.matches( '[data-smrt-shield-form]' ) ) {
            refreshForm( root, force );
        }

        root.querySelectorAll( '[data-smrt-shield-form]' ).forEach( function ( container ) {
            refreshForm( container, force );
        } );
    }

    scan( document );

    new MutationObserver( function ( mutations ) {
        mutations.forEach( function ( mutation ) {
            mutation.addedNodes.forEach( function ( node ) {
                scan( node, false );
            } );

            // A popup can be inserted fully and then reset by Elementor in the
            // same lifecycle. A short debounced pass runs after that sequence.
            const addsProtectedForm = Array.prototype.some.call( mutation.addedNodes, function ( node ) {
                return node.nodeType === Node.ELEMENT_NODE && (
                    ( node.matches && node.matches( '[data-smrt-shield-form]' ) ) ||
                    ( node.querySelector && node.querySelector( '[data-smrt-shield-form]' ) )
                );
            } );

            const popup = mutation.target.closest?.( '.elementor-popup-modal' );
            if ( addsProtectedForm && popup ) {
                schedulePopupScan( popup, 150 );
            }
        } );
    } ).observe( document.documentElement, { childList: true, subtree: true } );

    // Elementor can reuse and reset an existing popup form. Refresh after its
    // popup lifecycle event so reset hidden fields are hydrated again.
    if ( window.jQuery ) {
        window.jQuery( document ).on( 'elementor/popup/show', function ( event, id ) {
            const popup = document.getElementById( 'elementor-popup-modal-' + id );
            if ( popup ) {
                schedulePopupScan( popup, 750 );
            }
        } );
    }

    // Some Elementor versions dispatch the popup event before injecting or
    // resetting its form. The initiating click gives us a reliable late pass.
    document.addEventListener( 'click', function ( event ) {
        const trigger = event.target.closest ? event.target.closest( 'a[href*="popup%3Aopen"]' ) : null;

        if ( trigger ) {
            const href = trigger.getAttribute( 'href' );
            const match = href.match( /(?:%26|&)settings(?:%3D|=)([^&#]+)/i );
            if ( ! match ) {
                return;
            }
            try {
                const settings = JSON.parse( window.atob( decodeURIComponent( match[ 1 ] ) ) );
                window.setTimeout( function () {
                    const popup = document.getElementById( 'elementor-popup-modal-' + settings.id );
                    if ( popup ) {
                        scan( popup, true );
                    }
                }, 1000 );
            } catch ( error ) {
                // Elementor's popup event still handles other link formats.
            }
        }
    }, true );
}() );
