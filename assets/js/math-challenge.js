( function () {
    'use strict';

    const refreshed = new WeakSet();
    let forcedScanTimer = null;

    function scheduleForcedScan( delay ) {
        window.clearTimeout( forcedScanTimer );
        forcedScanTimer = window.setTimeout( function () {
            scan( document, true );
        }, delay || 150 );
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

            if ( addsProtectedForm ) {
                scheduleForcedScan();
            }
        } );
    } ).observe( document.documentElement, { childList: true, subtree: true } );

    // Elementor can reuse and reset an existing popup form. Refresh after its
    // popup lifecycle event so reset hidden fields are hydrated again.
    if ( window.jQuery ) {
        window.jQuery( document ).on( 'elementor/popup/show', function () {
            scheduleForcedScan( 750 );
        } );
    }

    // Some Elementor versions dispatch the popup event before injecting or
    // resetting its form. The initiating click gives us a reliable late pass.
    document.addEventListener( 'click', function ( event ) {
        const trigger = event.target.closest ? event.target.closest( 'a[href*="popup%3Aopen"]' ) : null;

        if ( trigger ) {
            scheduleForcedScan( 1000 );
        }
    }, true );
}() );
