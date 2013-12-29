YUI.add('moodle-enrol_relationship-quickenrolment', function(Y) {

    var CONTROLLERNAME = 'Quick relationship enrolment controller',
        relationshipNAME = 'relationship',
        relationshipID = 'relationshipid',
        ENROLLED = 'enrolled',
        NAME = 'name',
        USERS = 'users',
        COURSEID = 'courseid',
        ASSIGNABLEROLES = 'assignableRoles',
        DEFAULTrelationshipROLE = 'defaultrelationshipRole',
        relationshipS = 'relationships',
        MORERESULTS = 'moreresults',
        FIRSTPAGE = 'firstpage',
        OFFSET = 'offset',
        PANELID = 'qce-panel-',
        REQUIREREFRESH = 'requiresRefresh',
        SEARCH = 'search',
        URL = 'url',
        AJAXURL = 'ajaxurl',
        MANUALENROLMENT = 'manualEnrolment',
        CSS = {
            CLOSEBTN : 'close-button',
            relationship : 'qce-relationship',
            relationshipS : 'qce-relationships',
            relationshipBUTTON : 'qce-relationship-button',
            relationshipENROLLED : 'qce-relationship-enrolled',
            relationshipNAME : 'qce-relationship-name',
            relationshipUSERS : 'qce-relationship-users',
            ENROLUSERS : 'canenrolusers',
            FOOTER : 'qce-footer',
            HIDDEN : 'hidden',
            LIGHTBOX : 'qce-loading-lightbox',
            LOADINGICON : 'loading-icon',
            MORERESULTS : 'qce-more-results',
            PANEL : 'qce-panel',
            PANELCONTENT : 'qce-panel-content',
            PANELrelationshipS : 'qce-enrollable-relationships',
            PANELROLES : 'qce-assignable-roles',
            PANELCONTROLS : 'qce-panel-controls',
            SEARCH : 'qce-search'
        },
        COUNT = 0;


    var CONTROLLER = function(config) {
        CONTROLLER.superclass.constructor.apply(this, arguments);
    };
    CONTROLLER.prototype = {
        initializer : function(config) {
            COUNT ++;
            this.publish('assignablerolesloaded', {fireOnce:true});
            this.publish('relationshipsloaded');
            this.publish('defaultrelationshiproleloaded', {fireOnce:true});

            var finishbutton = Y.Node.create('<div class="'+CSS.CLOSEBTN+'"></div>')
                                   .append(Y.Node.create('<input type="button" value="'+M.str.enrol.finishenrollingusers+'" />'));
            var base = Y.Node.create('<div class="'+CSS.PANELCONTENT+'"></div>')
                .append(Y.Node.create('<div class="'+CSS.PANELROLES+'"></div>'))
                .append(Y.Node.create('<div class="'+CSS.PANELrelationshipS+'"></div>'))
                .append(Y.Node.create('<div class="'+CSS.FOOTER+'"></div>')
                    .append(Y.Node.create('<div class="'+CSS.SEARCH+'"><label for="enrolrelationshipsearch">'+M.str.enrol_relationship.relationshipsearch+':</label></div>')
                        .append(Y.Node.create('<input type="text" id="enrolrelationshipsearch" value="" />'))
                    )
                    .append(finishbutton)
                )
                .append(Y.Node.create('<div class="'+CSS.LIGHTBOX+' '+CSS.HIDDEN+'"></div>')
                    .append(Y.Node.create('<img alt="loading" class="'+CSS.LOADINGICON+'" />')
                        .setAttribute('src', M.util.image_url('i/loading', 'moodle')))
                    .setStyle('opacity', 0.5)
                );

            var close = Y.Node.create('<div class="close"></div>');
            var panel = new Y.Overlay({
                headerContent : Y.Node.create('<div></div>').append(Y.Node.create('<h2>'+M.str.enrol.enrolrelationship+'</h2>')).append(close),
                bodyContent : base,
                constrain : true,
                centered : true,
                id : PANELID+COUNT,
                visible : false
            });

            // display the wheel on ajax events
            Y.on('io:start', function() {
                base.one('.'+CSS.LIGHTBOX).removeClass(CSS.HIDDEN);
            }, this);
            Y.on('io:end', function() {
                base.one('.'+CSS.LIGHTBOX).addClass(CSS.HIDDEN);
            }, this);

            this.set(SEARCH, base.one('#enrolrelationshipsearch'));
            Y.on('key', this.getrelationships, this.get(SEARCH), 'down:13', this, false);

            panel.get('boundingBox').addClass(CSS.PANEL);
            panel.render(Y.one(document.body));
            this.on('show', function(){
                this.set('centered', true);
                this.show();
            }, panel);
            this.on('hide', panel.hide, panel);
            this.on('assignablerolesloaded', this.updateContent, this, panel);
            this.on('relationshipsloaded', this.updateContent, this, panel);
            this.on('defaultrelationshiproleloaded', this.updateContent, this, panel);
            Y.on('key', this.hide, document.body, 'down:27', this);
            close.on('click', this.hide, this);
            finishbutton.on('click', this.hide, this);

            Y.all('.enrol_relationship_plugin input').each(function(node){
                if (node.getAttribute('type', 'submit')) {
                    node.on('click', this.show, this);
                }
            }, this);

            base = panel.get('boundingBox');
            base.plug(Y.Plugin.Drag);
            base.dd.addHandle('.yui3-widget-hd h2');
            base.one('.yui3-widget-hd h2').setStyle('cursor', 'move');
        },
        show : function(e) {
            e.preventDefault();
            // prepare the data and display the window
            this.getrelationships(e, false);
            this.getAssignableRoles();
            this.fire('show');

            var rolesselect = Y.one('#id_enrol_relationship_assignable_roles');
            if (rolesselect) {
                rolesselect.focus();
            }
        },
        updateContent : function(e, panel) {
            var content, i, roles, relationships, count=0, supportmanual = this.get(MANUALENROLMENT), defaultrole;
            switch (e.type.replace(/^[^:]+:/, '')) {
                case 'relationshipsloaded' :
                    if (this.get(FIRSTPAGE)) {
                        // we are on the page 0, create new element for relationships list
                        content = Y.Node.create('<div class="'+CSS.relationshipS+'"></div>');
                        if (supportmanual) {
                            content.addClass(CSS.ENROLUSERS);
                        }
                    } else {
                        // we are adding relationships to existing list
                        content = Y.Node.one('.'+CSS.PANELrelationshipS+' .'+CSS.relationshipS);
                        content.one('.'+CSS.MORERESULTS).remove();
                    }
                    // add relationships items to the content
                    relationships = this.get(relationshipS);
                    for (i in relationships) {
                        count++;
                        relationships[i].on('enrolchort', this.enrolrelationship, this, relationships[i], panel.get('contentBox'), false);
                        relationships[i].on('enrolusers', this.enrolrelationship, this, relationships[i], panel.get('contentBox'), true);
                        content.append(relationships[i].toHTML(supportmanual).addClass((count%2)?'even':'odd'));
                    }
                    // add the next link if there are more items expected
                    if (this.get(MORERESULTS)) {
                        var fetchmore = Y.Node.create('<div class="'+CSS.MORERESULTS+'"><a href="#">'+M.str.enrol_relationship.ajaxmore+'</a></div>');
                        fetchmore.on('click', this.getrelationships, this, true);
                        content.append(fetchmore);
                    }
                    // finally assing the content to the block
                    if (this.get(FIRSTPAGE)) {
                        panel.get('contentBox').one('.'+CSS.PANELrelationshipS).setContent(content);
                    }
                    break;
                case 'assignablerolesloaded':
                    roles = this.get(ASSIGNABLEROLES);
                    content = Y.Node.create('<select id="id_enrol_relationship_assignable_roles"></select>');
                    for (i in roles) {
                        content.append(Y.Node.create('<option value="'+i+'">'+roles[i]+'</option>'));
                    }
                    panel.get('contentBox').one('.'+CSS.PANELROLES).setContent(Y.Node.create('<div><label for="id_enrol_relationship_assignable_roles">'+M.str.role.assignroles+':</label></div>').append(content));

                    this.getDefaultrelationshipRole();
                    Y.one('#id_enrol_relationship_assignable_roles').focus();
                    break;
                case 'defaultrelationshiproleloaded':
                    defaultrole = this.get(DEFAULTrelationshipROLE);
                    panel.get('contentBox').one('.'+CSS.PANELROLES+' select').set('value', defaultrole);
                    break;
            }
        },
        hide : function() {
            if (this.get(REQUIREREFRESH)) {
                window.location = this.get(URL);
            }
            this.fire('hide');
        },
        getrelationships : function(e, append) {
            if (e) {
                e.preventDefault();
            }
            if (append) {
                this.set(FIRSTPAGE, false);
            } else {
                this.set(FIRSTPAGE, true);
                this.set(OFFSET, 0);
            }
            var params = [];
            params['id'] = this.get(COURSEID);
            params['offset'] = this.get(OFFSET);
            params['search'] = this.get(SEARCH).get('value');
            params['action'] = 'getrelationships';
            params['sesskey'] = M.cfg.sesskey;

            Y.io(M.cfg.wwwroot+this.get(AJAXURL), {
                method:'POST',
                data:build_querystring(params),
                on: {
                    complete: function(tid, outcome, args) {
                        try {
                            var relationships = Y.JSON.parse(outcome.responseText);
                            if (relationships.error) {
                                new M.core.ajaxException(relationships);
                            } else {
                                this.setrelationships(relationships.response);
                            }
                        } catch (e) {
                            return new M.core.exception(e);
                        }
                        this.fire('relationshipsloaded');
                    }
                },
                context:this
            });
        },
        setrelationships : function(response) {
            this.set(MORERESULTS, response.more);
            this.set(OFFSET, response.offset);
            var rawrelationships = response.relationships;
            var relationships = [], i=0;
            for (i in rawrelationships) {
                relationships[i] = new relationship(rawrelationships[i]);
            }
            this.set(relationshipS, relationships);
        },
        getAssignableRoles : function() {
            Y.io(M.cfg.wwwroot+this.get(AJAXURL), {
                method:'POST',
                data:'id='+this.get(COURSEID)+'&action=getassignable&sesskey='+M.cfg.sesskey,
                on: {
                    complete: function(tid, outcome, args) {
                        try {
                            var roles = Y.JSON.parse(outcome.responseText);
                            this.set(ASSIGNABLEROLES, roles.response);
                        } catch (e) {
                            return new M.core.exception(e);
                        }
                        this.getAssignableRoles = function() {
                            this.fire('assignablerolesloaded');
                        };
                        this.getAssignableRoles();
                    }
                },
                context:this
            });
        },
        getDefaultrelationshipRole : function() {
            Y.io(M.cfg.wwwroot+this.get(AJAXURL), {
                method:'POST',
                data:'id='+this.get(COURSEID)+'&action=getdefaultrelationshiprole&sesskey='+M.cfg.sesskey,
                on: {
                    complete: function(tid, outcome, args) {
                        try {
                            var roles = Y.JSON.parse(outcome.responseText);
                            this.set(DEFAULTrelationshipROLE, roles.response);
                        } catch (e) {
                            return new M.core.exception(e);
                        }
                        this.fire('defaultrelationshiproleloaded');
                    }
                },
                context:this
            });
        },
        enrolrelationship : function(e, relationship, node, usersonly) {
            var params = {
                id : this.get(COURSEID),
                roleid : node.one('.'+CSS.PANELROLES+' select').get('value'),
                relationshipid : relationship.get(relationshipID),
                action : (usersonly)?'enrolrelationshipusers':'enrolrelationship',
                sesskey : M.cfg.sesskey
            };
            Y.io(M.cfg.wwwroot+this.get(AJAXURL), {
                method:'POST',
                data:build_querystring(params),
                on: {
                    complete: function(tid, outcome, args) {
                        try {
                            var result = Y.JSON.parse(outcome.responseText);
                            if (result.error) {
                                new M.core.ajaxException(result);
                            } else {
                                if (result.response && result.response.message) {
                                    var alertpanel = new M.core.alert(result.response);
                                    Y.Node.one('#id_yuialertconfirm-' + alertpanel.COUNT).focus();
                                }
                                var enrolled = Y.Node.create('<div class="'+CSS.relationshipBUTTON+' alreadyenrolled">'+M.str.enrol.synced+'</div>');
                                node.one('.'+CSS.relationship+' #relationshipid_'+relationship.get(relationshipID)).replace(enrolled);
                                this.set(REQUIREREFRESH, true);
                            }
                        } catch (e) {
                            new M.core.exception(e);
                        }
                    }
                },
                context:this
            });
            return true;
        }
    };
    Y.extend(CONTROLLER, Y.Base, CONTROLLER.prototype, {
        NAME : CONTROLLERNAME,
        ATTRS : {
            url : {
                validator : Y.Lang.isString
            },
            ajaxurl : {
                validator : Y.Lang.isString
            },
            courseid : {
                value : null
            },
            relationships : {
                validator : Y.Lang.isArray,
                value : null
            },
            assignableRoles : {
                value : null
            },
            manualEnrolment : {
                value : false
            },
            defaultrelationshipRole : {
                value : null
            },
            requiresRefresh : {
                value : false,
                validator : Y.Lang.isBool
            }
        }
    });
    Y.augment(CONTROLLER, Y.EventTarget);

    var relationship = function(config) {
        relationship.superclass.constructor.apply(this, arguments);
    };
    Y.extend(relationship, Y.Base, {
        toHTML : function(supportmanualenrolment){
            var button, result, name, users, syncbutton, usersbutton;
            result = Y.Node.create('<div class="'+CSS.relationship+'"></div>');
            if (this.get(ENROLLED)) {
                button = Y.Node.create('<div class="'+CSS.relationshipBUTTON+' alreadyenrolled">'+M.str.enrol.synced+'</div>');
            } else {
                button = Y.Node.create('<div id="relationshipid_'+this.get(relationshipID)+'"></div>');

                syncbutton = Y.Node.create('<a class="'+CSS.relationshipBUTTON+' notenrolled enrolrelationship">'+M.str.enrol.enrolrelationship+'</a>');
                syncbutton.on('click', function(){this.fire('enrolchort');}, this);
                button.append(syncbutton);

                if (supportmanualenrolment) {
                    usersbutton = Y.Node.create('<a class="'+CSS.relationshipBUTTON+' notenrolled enrolusers">'+M.str.enrol.enrolrelationshipusers+'</a>');
                    usersbutton.on('click', function(){this.fire('enrolusers');}, this);
                    button.append(usersbutton);
                }
            }
            name = Y.Node.create('<div class="'+CSS.relationshipNAME+'">'+this.get(NAME)+'</div>');
            users = Y.Node.create('<div class="'+CSS.relationshipUSERS+'">'+this.get(USERS)+'</div>');
            return result.append(button).append(name).append(users);
        }
    }, {
        NAME : relationshipNAME,
        ATTRS : {
            relationshipid : {

            },
            name : {
                validator : Y.Lang.isString
            },
            enrolled : {
                value : false
            },
            users : {
                value : 0
            }
        }
    });
    Y.augment(relationship, Y.EventTarget);

    M.enrol_relationship = M.enrol || {};
    M.enrol_relationship.quickenrolment = {
        init : function(cfg) {
            new CONTROLLER(cfg);
        }
    }

}, '@VERSION@', {requires:['base','node', 'overlay', 'io-base', 'test', 'json-parse', 'event-delegate', 'dd-plugin', 'event-key', 'moodle-core-notification']});
