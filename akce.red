Red[]

source: read %akce.html
data: make block! 1000
dq: #"^""
ws: charset " ^/^-^M"

store: func [value] [
    append data value
]

parse source [
    some [
        thru {<tr}
        thru {data-product="} ; id
        copy value to dq
        (store "---")
        (store value)
        thru {<a}
        thru {data-product="} ; produkt
        copy value to dq
        (store value)
        thru {<a}
        thru {data-shop="} ; shop
        copy value to dq
        (store value)
        thru <td class="text-left discounts_price">
        thru <strong class="discount_price_value"> ; price
        copy value to {</strong>}
        (store value)
        thru <div class="discount_percentage"> ; discount
        copy value to {</div>}
        (store value)
        thru {</tr>}
    ]
]

foreach x data [print x]
