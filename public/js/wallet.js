async function connectWallet() {
    if (!window.ethereum) {
        alert("MetaMaskが必要です");
        return;
    }

    const accounts = await window.ethereum.request({
        method: "eth_requestAccounts"
    });

    console.log("wallet:", accounts[0]);

    return accounts[0];
}

const JPYC_ADDRESS = "0xYOUR_JPYC_CONTRACT";

const ABI = [
    "function transfer(address to, uint256 amount) returns (bool)"
];

async function payWithMetaMask(to, amount) {
    const provider = new ethers.BrowserProvider(window.ethereum);
    const signer = await provider.getSigner();

    const contract = new ethers.Contract(JPYC_ADDRESS, ABI, signer);

    const tx = await contract.transfer(to, amount);

    console.log("tx hash:", tx.hash);

    return tx.hash;
}