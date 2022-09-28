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
        thru {<tr}
        thru {data-product="}
        copy value to dq ; id
        (store "---")
        (store value)
        thru {<a}
        thru {data-product="}
        copy value to dq ; product
        (store value)
        thru {<a}
        thru {data-shop="}
        copy value to dq ; shop
        (store value)
        thru <strong class="discount_price_value">
        copy value to {</strong>} ; price
        (store value)
        thru {</tr>}
    ]
]

foreach x data [print x]
