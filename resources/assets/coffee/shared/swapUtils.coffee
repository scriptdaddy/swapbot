# swapUtils functions
swapbot = {} if not swapbot?

swapbot.swapUtils = do ()->
    exports = {}

    # #############################################
    # local

    buildDesc = {}
    buildDesc.rate = (swap)->
        outAmount = 1 * swap.rate
        inAmount = 1
        return "#{outAmount} #{swap.out} for #{inAmount} #{swap.in}"

    buildDesc.fixed = (swap)->
        return "#{swap.out_qty} #{swap.out} for #{swap.in_qty} #{swap.in}"


    buildInAmountFromOutAmount = {}
    buildInAmountFromOutAmount.rate = (outAmount, swap)->
        if not outAmount? or isNaN(outAmount)
            return 0

        inAmount = outAmount / swap.rate
        return inAmount

    # this needs to be refined further
    buildInAmountFromOutAmount.fixed = (outAmount, swap)->
        if not outAmount? or isNaN(outAmount)
            return 0

        inAmount = outAmount / (swap.out_qty / swap.in_qty)

        return inAmount




    # #############################################
    # exports

    exports.exchangeDescription = (swap)->
        return buildDesc[swap.strategy](swap)
    
    exports.inAmountFromOutAmount = (inAmount, swap)->
        inAmount = buildInAmountFromOutAmount[swap.strategy](inAmount, swap)
        inAmount = 0 if inAmount == NaN
        return inAmount

    return exports

