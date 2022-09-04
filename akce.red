Red[]

source: read %akce.html
data: make block! 10000
dq: #"^""
ws: charset " ^/^-^M"

store: func [value] [
    append data value
]

parse source [
    some [
        thru <table class="wide discounts_table">
        some [
            some ws
            {<tr}
            thru {data-product="} ; id
            copy value to dq
            (store value)
            thru {id="} ; id
            copy value to dq
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
            thru <strong class="discount_price_value"> ; cena
            copy value to </strong>
            (store value)
            thru <div class="discount_percentage"> ; sleva
            copy value to </div>
            (store value)
            thru </table>
        ]
    ]
]

;write-stdout data
foreach x data [print x]
