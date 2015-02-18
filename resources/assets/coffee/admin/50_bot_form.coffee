do ()->

    sbAdmin.ctrl.botForm = {}

    # ### helpers #####################################
    swapGroupRenderers = {}
    swapGroupRenderers.rate = (number, swap)->
        return m("div", {class: "asset-group"}, [
            m("h4", "Swap ##{number}"),
            m("div", { class: "row"}, [
                m("div", {class: "col-md-3"}, [
                    sbAdmin.form.mFormField("Swap Type", {id: "swap_strategy_#{number}", type: 'select', options: sbAdmin.swaputils.allStrategyOptions()}, swap.strategy),
                ]),
                m("div", {class: "col-md-3"}, [
                    sbAdmin.form.mFormField("Receives Asset", {id: "swap_in_#{number}", 'placeholder': "BTC", }, swap.in),
                ]),
                m("div", {class: "col-md-3"}, [
                    sbAdmin.form.mFormField("Sends Asset", {id: "swap_out_#{number}", 'placeholder': "LTBCOIN", }, swap.out),
                ]),
                m("div", {class: "col-md-2"}, [
                    sbAdmin.form.mFormField("At Rate", {type: "number", step: "any", min: "0", id: "swap_rate_#{number}", 'placeholder': "0.000001", }, swap.rate),
                ]),
                m("div", {class: "col-md-1"}, [
                    m("a", {class: "remove-link", href: '#remove', onclick: vm.buildRemoveSwapFn(number), style: if number == 1 then {display: 'none'} else ""}, [
                        m("span", {class: "glyphicon glyphicon-remove-circle", title: "Remove Swap #{number}"}, ''),
                    ]),
                ]),
            ]),
        ])

    swapGroupRenderers.fixed = (number, swap)->
        return m("div", {class: "asset-group"}, [
            m("h4", "Swap ##{number}"),
            m("div", { class: "row"}, [
                m("div", {class: "col-md-3"}, [
                    sbAdmin.form.mFormField("Swap Type", {id: "swap_strategy_#{number}", type: 'select', options: sbAdmin.swaputils.allStrategyOptions()}, swap.strategy),
                ]),
                m("div", {class: "col-md-2"}, [
                    sbAdmin.form.mFormField("Receives Asset", {id: "swap_in_#{number}", 'placeholder': "BTC", }, swap.in),
                ]),
                m("div", {class: "col-md-2"}, [
                    sbAdmin.form.mFormField("Receives Quantity", {type: "number", step: "any", min: "0", id: "swap_in_qty_#{number}", 'placeholder': "1", }, swap.in_qty),
                ]),
                m("div", {class: "col-md-2"}, [
                    sbAdmin.form.mFormField("Sends Asset", {id: "swap_out_#{number}", 'placeholder': "LTBCOIN", }, swap.out),
                ]),
                m("div", {class: "col-md-2"}, [
                    sbAdmin.form.mFormField("Sends Quantity", {type: "number", step: "any", min: "0", id: "swap_out_qty_#{number}", 'placeholder': "1", }, swap.out_qty),
                ]),
                m("div", {class: "col-md-1"}, [
                    m("a", {class: "remove-link", href: '#remove', onclick: vm.buildRemoveSwapFn(number), style: if number == 1 then {display: 'none'} else ""}, [
                        m("span", {class: "glyphicon glyphicon-remove-circle", title: "Remove Swap #{number}"}, ''),
                    ]),
                ]),
            ]),
        ])

    swapGroup = (number, swapProp)->
        return swapGroupRenderers[swapProp().strategy()](number, swapProp())


    # ################################################

    vm = sbAdmin.ctrl.botForm.vm = do ()->
        buildSwapsPropValue = (swaps)->
            out = []
            for swap in swaps
                out.push(sbAdmin.swaputils.newSwapProp(swap))

            # always have at least one
            if not out.length
                out.push(sbAdmin.swaputils.newSwapProp())

            return out


        buildBlacklistAddressesPropValue = (addresses)->
            out = []
            for address in addresses
                out.push(m.prop(address))

            # always have at least one
            if not out.length
                out.push(m.prop(''))

            return out

        vm = {}
        vm.init = ()->
            # view status
            vm.errorMessages = m.prop([])
            vm.formStatus = m.prop('active')
            vm.resourceId = m.prop('')

            # fields
            vm.name = m.prop('')
            vm.description = m.prop('')
            vm.returnFee = m.prop(0.0001)
            vm.swaps = m.prop([sbAdmin.swaputils.newSwapProp()])
            vm.blacklistAddresses = m.prop([m.prop('')])

            # if there is an id, then load it from the api
            id = m.route.param('id')
            if id != 'new'
                # load the bot info from the api
                sbAdmin.api.getBot(id).then(
                    (botData)->
                        vm.resourceId(botData.id)

                        vm.name(botData.name)
                        vm.description(botData.description)
                        vm.swaps(buildSwapsPropValue(botData.swaps))
                        vm.blacklistAddresses(buildBlacklistAddressesPropValue(botData.blacklistAddresses))
                        vm.returnFee(botData.returnFee or 0.0001)

                        return
                    , (errorResponse)->
                        vm.errorMessages(errorResponse.errors)
                        return
                )

            vm.addSwap = (e)->
                e.preventDefault()
                vm.swaps().push(sbAdmin.swaputils.newSwapProp())
                return

            vm.buildRemoveSwapFn = (number)->
                return (e)->
                    e.preventDefault()

                    # filter newSwaps
                    newSwaps = vm.swaps().filter (swap, index)->
                        return (index != number - 1)
                    vm.swaps(newSwaps)
                    return

            vm.addBlacklistAddress = (e)->
                e.preventDefault()
                vm.blacklistAddresses().push(m.prop(''))
                return

            vm.buildRemoveBlacklistAddress = (number)->
                return (e)->
                    e.preventDefault()

                    # filter newBlacklistAddresses
                    newBlacklistAddresses = vm.blacklistAddresses().filter (blacklistAddress, index)->
                        return (index != number - 1)
                    vm.blacklistAddresses(newBlacklistAddresses)
                    return

            vm.save = (e)->
                e.preventDefault()

                attributes = {
                    name: vm.name()
                    description: vm.description()
                    blacklistAddresses: vm.blacklistAddresses()
                    swaps: vm.swaps()
                    returnFee: vm.returnFee()
                }

                if vm.resourceId().length > 0
                    # update existing bot
                    apiCall = sbAdmin.api.updateBot
                    apiArgs = [vm.resourceId(), attributes]
                else
                    # new bot
                    apiCall = sbAdmin.api.newBot
                    apiArgs = [attributes]

                sbAdmin.form.submit(apiCall, apiArgs, vm.errorMessages, vm.formStatus).then(()->
                    console.log "submit complete - routing to dashboard"
                    # back to dashboard
                    m.route('/admin/dashboard')
                    return
                )

            return
        return vm

    sbAdmin.ctrl.botForm.controller = ()->
        # require login
        sbAdmin.auth.redirectIfNotLoggedIn()

        vm.init()
        return

    sbAdmin.ctrl.botForm.view = ()->
        mEl = m("div", [
            m("div", { class: "row"}, [
                m("div", {class: "col-md-12"}, [
                    m("h2", if vm.resourceId() then "Edit SwapBot #{vm.name()}" else "Create a New Swapbot"),

                    m("div", {class: "spacer1"}),

                    # m("form", {onsubmit: vm.save, }, [
                    sbAdmin.form.mForm({errors: vm.errorMessages, status: vm.formStatus}, {onsubmit: vm.save}, [
                        sbAdmin.form.mAlerts(vm.errorMessages),

                        sbAdmin.form.mFormField("Bot Name", {id: 'name', 'placeholder': "Bot Name", required: true, }, vm.name),
                        sbAdmin.form.mFormField("Bot Description", {type: 'textarea', id: 'description', 'placeholder': "Bot Description", required: true, }, vm.description),


                        m("hr"),

                        m("h4", "Settings"),
                        m("h5", "Blacklisted Addresses"),
                        m("p", [m("small", "Blacklisted addresses do not trigger swaps and can be used to load the SwapBot.")]),
                        vm.blacklistAddresses().map((address, offset)->
                            number = offset+1
                            return m("div", {class: "form-group"}, [
                                m("div", { class: "row"}, [
                                    m("div", {class: "col-md-5"}, [
                                        sbAdmin.form.mInputEl({id: "blacklist_address_#{number}", 'placeholder': "1xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", }, address),
                                    ]),
                                    m("div", {class: "col-md-1"}, [
                                        m("a", {class: "remove-link remove-link-compact", href: '#remove', onclick: vm.buildRemoveBlacklistAddress(number), style: if number == 1 then {display: 'none'} else ""}, [
                                            m("span", {class: "glyphicon glyphicon-remove-circle", title: "Remove Address #{number}"}, ''),
                                        ]),
                                    ]),
                                ]),
                            ])
                        ),

                        # add blacklist address
                        m("div", {class: "form-group"}, [
                                m("a", {class: "", href: '#add-address', onclick: vm.addBlacklistAddress}, [
                                    m("span", {class: "glyphicon glyphicon-plus"}, ''),
                                    m("span", {}, ' Add Another Blacklist Address'),
                                ]),
                        ]),

                        # return fee
                        m("div", {class: "spacer1"}),
                        m("div", { class: "row"}, [
                            m("div", {class: "col-md-5"}, [
                                sbAdmin.form.mFormField("Return Transaction Fee", {id: 'name', 'placeholder': "0.0001", required: true, }, vm.returnFee),
                            ]),
                        ]),

                        m("hr"),

                        vm.swaps().map((swap, offset)->
                            return swapGroup(offset+1, swap)
                        ),

                        # add asset
                        m("div", {class: "form-group"}, [
                                m("a", {class: "", href: '#add', onclick: vm.addSwap}, [
                                    m("span", {class: "glyphicon glyphicon-plus"}, ''),
                                    m("span", {}, ' Add Another Asset'),
                                ]),
                        ]),


                        m("div", {class: "spacer1"}),

                        sbAdmin.form.mSubmitBtn("Save Bot"),
                        m("a[href='/admin/dashboard']", {class: "btn btn-default pull-right", config: m.route}, "Return without Saving"),
                        

                    ]),

                ]),
            ]),



        ])
        return [sbAdmin.nav.buildNav(), sbAdmin.nav.buildInContainer(mEl)]


