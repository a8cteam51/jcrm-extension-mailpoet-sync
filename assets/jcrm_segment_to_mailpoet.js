/**
 * This file takes care of the logic after the button "Export to MailPoet" is clicked.
 * This button is found on Jetpack CRM > Segments screen
 */
jQuery( function () {

    jQuery( '#zbs-segment-export-mailpoet' ).off( 'click' ).on( 'click', function () {

        // Segment must exist
        if ( ! window.zbsSegment.id ) {
            console.log('Cannot export to MailPoet without segment ID');
            return;
        }

        if ( !window.zbsAJAXSending ) {
            window.zbsAJAXSending = true;

            // id's
            var snameid = 'zbs-segment-edit-var-title';
            var smatchtypeid = 'zbs-segment-edit-var-matchtype';
            var sconditions = get_sconditions();

    		// pull through vars
            var sname = jQuery( '#' + snameid ).val();
            var smatchtype = jQuery( '#' + smatchtypeid ).val();

            var segment = {
                id: window.zbsSegment.id,
                title: sname,
                matchtype: smatchtype,
                conditions: sconditions

            };            

            // get deets - whatever's passed is updated, so don't pass nulls
            var data = {
                'action': 'zbs_mailpoet_sync_export_segment',
                'test': 'test',
                'sID': segment.id,
                'sec': window.zbsSegmentSEC
            };

            // pass into data
            if ( typeof segment.title != "undefined" ) data.sTitle = segment.title;
            if ( typeof segment.matchtype != "undefined" ) data.sMatchType = segment.matchtype;
            if ( typeof segment.conditions != "undefined" ) data.sConditions = segment.conditions;


            // Send it Pat :D
            jQuery.ajax( {
                type: "POST",
                url: ajaxurl,
                "data": data,
                timeout: 20000,
                success: function ( response ) {

                    console.log("response",response);
                    if ( response.ok == true ) {
                        alert(`This segment is being exported to a MailPoet mailing list with ${response.count} subscribers.`)
                    } else {
                        alert('Something went wrong. This segment could not be exported to MailPoet')
                    }

                    // unblock
                    window.zbsAJAXSending = false;

                    // any callback
                    if ( typeof callback == "function" ) callback( response );

                    return true;

                },
                error: function ( response ) {

                    //console.log('err',response);

                    // unblock
                    window.zbsAJAXSending = false;

                    // any callback
                    if ( typeof cbfail == "function" ) cbfail( response );

                    return false;

                }

            } );

        }
    } );

    function get_sconditions () {
        // check conditions
        var sconditions = [];
        jQuery( '.zbs-segment-edit-condition' ).each( function ( ind, ele ) {
    
            // get vars
            var type = jQuery( '.zbs-segment-edit-var-condition-type', jQuery( ele ) ).val();
            var operator = jQuery( '.zbs-segment-edit-var-condition-operator', jQuery( ele ) ).val();
            var value1 = jQuery( '.zbs-segment-edit-var-condition-value', jQuery( ele ) ).val();
            var value2 = jQuery( '.zbs-segment-edit-var-condition-value-2', jQuery( ele ) ).val();
    
            // operator will be empty for those such as tagged
            if ( typeof operator == "undefined" || operator == "undefined" ) operator = -1;
    
            var condition = {
    
                'type': type,
                'operator': operator,
                'value': value1,
                'value2': value2
    
            };
    
            // push
            sconditions.push( condition );
    
        } );
    
        return sconditions;
    }
} );