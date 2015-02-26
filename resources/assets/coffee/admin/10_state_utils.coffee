# stateutils functions
sbAdmin.stateutils = do ()->
    stateutils = {}

    stateutils.buildStateSpan = (stateValue)->
        switch stateValue
            when 'brandnew'
                return m("span", {class: 'no'}, stateutils.buildStateLabel(stateValue))
            when 'lowfuel'
                return m("span", {class: 'no'}, stateutils.buildStateLabel(stateValue))
            when 'active'
                return m("span", {class: 'yes'}, stateutils.buildStateLabel(stateValue))
            else
                return m("span", {class: 'no'}, stateutils.buildStateLabel(stateValue))
        return


    stateutils.buildStateLabel = (stateValue)->
        switch stateValue
            when 'brandnew'
                return "Waiting for Payment"
            when 'lowfuel'
                return "Low Fuel"
            when 'active'
                return "Active"
            else
                return "Inactive"
        

    stateutils.buildStateDetails = (stateValue, planName, paymentAddress, botAddress)->
        details = {
            label: ''
            subtitle: ''
            class: ''
        }

        switch stateValue
            when 'brandnew'
                planDetails = sbAdmin.planutils.planData(planName)
                amount = planDetails.creationFee + planDetails.initialFuel
                details.label = stateutils.buildStateLabel(stateValue)
                details.subtitle = "This is a new swapbot and needs to be paid to be activated.  Please send a payment of #{sbAdmin.currencyutils.formatValue(amount)} to #{paymentAddress}.  This is a payment of #{planDetails.creationFee} BTC for the creation of the bot and #{planDetails.initialFuel} BTC as fuel to send transactions."
                details.class = "panel-warning inactive new"
            when 'lowfuel'
                details.label = stateutils.buildStateLabel(stateValue)
                details.subtitle = "This swapbot is low on BTC fuel.  Please send 0.005 BTC to #{paymentAddress}."
                details.class = "panel-warning inactive lowfuel"
            when 'active'
                details.label = stateutils.buildStateLabel(stateValue)
                details.subtitle = "This swapbot is up and running.  All is well."
                details.class = "panel-success active"
            else
                details.label = stateutils.buildStateLabel(stateValue)
                details.subtitle = "This swapbot is inactive.  Swaps are not being processed."
                details.class = "panel-danger inactive deactivated"
        
        return details

    stateutils.buildStateDisplay = (details)->
        return m("div", {class: "panel #{details.class}"}, [
            m("div", {class: 'panel-heading'}, [
                m("h3", {class: 'panel-title'}, details.label),
            ]),
            m("div", {class: 'panel-body'}, details.subtitle),
        ])

    return stateutils